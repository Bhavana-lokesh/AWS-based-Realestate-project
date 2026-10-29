<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

function chatbotReply(string $answer, array $properties = [], int $status = 200, bool $loginRequired = false): never
{
    http_response_code($status);
    $response = ['answer' => $answer, 'properties' => $properties];
    if ($loginRequired) {
        $response['login_required'] = true;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function chatbotProperties(mysqli $con, array $filters, array $ids = [], bool $aboveBudget = false): array
{
    $conditions = [
        "p.status = 'available'",
        "p.review_status = 'approved'",
        "EXISTS (SELECT 1 FROM user owner WHERE owner.user_id = p.seller_id AND LOWER(owner.role) IN ('agent', 'seller') AND owner.is_active = 1)",
    ];
    $values = [];
    $types = '';
    if (currentUserRole() === 'buyer' && currentUserId() > 0) {
        $conditions[] = 'p.seller_id <> ?';
        $values[] = currentUserId();
        $types .= 'i';
    }

    foreach (['location' => 'p.location', 'property_type' => 'p.property_type', 'bedrooms' => 'p.bedrooms'] as $key => $column) {
        if (isset($filters[$key]) && $filters[$key] !== '') {
            $conditions[] = $key === 'location' ? "$column LIKE ?" : "$column = ?";
            $values[] = $key === 'location' ? '%' . $filters[$key] . '%' : $filters[$key];
            $types .= $key === 'bedrooms' ? 'i' : 's';
        }
    }

    if (isset($filters['max_price']) && $filters['max_price'] > 0) {
        if ($aboveBudget) {
            $conditions[] = 'p.price > ? AND p.price <= ?';
            $values[] = $filters['max_price'];
            $values[] = $filters['max_price'] * 1.1;
            $types .= 'dd';
        } else {
            $conditions[] = 'p.price <= ?';
            $values[] = $filters['max_price'];
            $types .= 'd';
        }
    }
    if (isset($filters['min_price']) && $filters['min_price'] > 0) {
        $conditions[] = 'p.price >= ?';
        $values[] = $filters['min_price'];
        $types .= 'd';
    }
    if (!empty($filters['near_school'])) {
        $conditions[] = "p.nearest_school IS NOT NULL AND p.nearest_school <> ''";
    }
    if (!empty($filters['pool_available'])) {
        $conditions[] = "p.pool_available = 'Yes'";
    }
    if (!empty($filters['is_dog_friendly'])) {
        $conditions[] = "p.is_dog_friendly = 'Yes'";
    }
    if ($ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids) {
            return [];
        }
        $conditions[] = 'p.property_id IN (' . implode(',', $ids) . ')';
    }

    $sql = "SELECT p.property_id, p.title, p.description, p.price, p.location, p.property_type,
                   p.bedrooms, p.bathrooms, p.size_sqft, p.nearest_school, p.bus_availability,
                   p.tram_availability, p.pool_available, p.is_dog_friendly,
                   (SELECT image_url FROM property_image WHERE property_id = p.property_id ORDER BY image_id LIMIT 1) AS image_url
            FROM property_listings p WHERE " . implode(' AND ', $conditions) .
            ' ORDER BY p.created_at DESC LIMIT 8';
    $statement = $con->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare property search.');
    }
    if ($values) {
        $references = [];
        foreach ($values as $key => &$value) {
            $references[$key] = &$value;
        }
        $statement->bind_param($types, ...$references);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function chatbotFilters(string $message, array $saved): array
{
    $filters = $saved;
    $text = mb_strtolower($message, 'UTF-8');

    if (preg_match('/\b(rent|rental|renting|lease)\b/u', $text)) {
        $filters['property_type'] = 'Rental';
    } elseif (preg_match('/\b(for sale|buy|purchase|sale)\b/u', $text)) {
        $filters['property_type'] = 'Sale';
    }
    if (preg_match('/\b(\d{1,2})\s*(?:bhk|bed(?:room)?s?)\b/u', $text, $match)) {
        $filters['bedrooms'] = min(20, (int)$match[1]);
    }
    if (preg_match('/\b(?:under|below|less than|max(?:imum)?|within|budget(?: of)?)\s*(?:₹|rs\.?\s*)?([\d,.]+)\s*(crore|crores|cr|lakh|lakhs|lac|lacs|k)?\b/u', $text, $match)) {
        $amount = (float)str_replace(',', '', $match[1]);
        $unit = $match[2] ?? '';
        $amount *= in_array($unit, ['crore', 'crores', 'cr'], true) ? 10000000 : (in_array($unit, ['lakh', 'lakhs', 'lac', 'lacs'], true) ? 100000 : ($unit === 'k' ? 1000 : 1));
        if ($amount > 0 && $amount <= 1000000000000) {
            $filters['max_price'] = $amount;
        }
    }
    if (preg_match('/\b(?:above|over|at least|minimum)\s*(?:₹|rs\.?\s*)?([\d,.]+)\s*(crore|crores|cr|lakh|lakhs|lac|lacs|k)?\b/u', $text, $match)) {
        $amount = (float)str_replace(',', '', $match[1]);
        $unit = $match[2] ?? '';
        $amount *= in_array($unit, ['crore', 'crores', 'cr'], true) ? 10000000 : (in_array($unit, ['lakh', 'lakhs', 'lac', 'lacs'], true) ? 100000 : ($unit === 'k' ? 1000 : 1));
        if ($amount > 0 && $amount <= 1000000000000) {
            $filters['min_price'] = $amount;
        }
    }
    if (preg_match_all('/\b(?:in|near|at)\s+([a-z][a-z .-]{1,60}?)(?=\s+(?:in|near|at|under|below|less|for sale|for rent|with|and|having)\b|[,.!?]|$)/iu', $message, $matches)) {
        foreach ($matches[1] as $candidate) {
            $location = trim($candidate);
            if ($location !== '' && !preg_match('/^schools?$/i', $location)) {
                $filters['location'] = mb_substr($location, 0, 80);
            }
        }
    }
    if (preg_match('/\bnear(?:by)?\s+(?:a\s+)?schools?\b/u', $text)) {
        $filters['near_school'] = true;
    }
    if (preg_match('/\bpool\b/u', $text)) {
        $filters['pool_available'] = true;
    }
    if (preg_match('/\b(?:dog|pet)[ -]?friendly\b/u', $text)) {
        $filters['is_dog_friendly'] = true;
    }
    return $filters;
}

function chatbotDateTime(string $message): ?string
{
    $date = null;
    $text = mb_strtolower($message, 'UTF-8');
    $today = new DateTimeImmutable('today');
    if (preg_match('/\b(today|tomorrow)\b/u', $text, $match)) {
        $date = $today->modify($match[1] === 'tomorrow' ? '+1 day' : '+0 day');
    } elseif (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u', $text, $match)) {
        $targetDay = ucfirst($match[1]);
        $date = $today->modify($targetDay);
        if ($date < $today) {
            $date = $date->modify('+7 days');
        }
    } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $text, $match)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]) ?: null;
    }
    if (!$date) {
        return null;
    }

    if (!preg_match('/\b(?:at\s*)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b|\b(?:at\s+)([01]?\d|2[0-3]):([0-5]\d)\b/u', $text, $time)) {
        return $date->format('Y-m-d');
    }
    if (!empty($time[1])) {
        $hour = (int)$time[1] % 12 + (($time[3] ?? '') === 'pm' ? 12 : 0);
        $minute = (int)($time[2] ?? 0);
    } else {
        $hour = (int)$time[4];
        $minute = (int)$time[5];
    }
    return $date->setTime($hour, $minute)->format('Y-m-d H:i:s');
}

function chatbotPropertyId(string $message, array $properties, ?int $contextId): ?int
{
    if ($contextId) {
        return $contextId;
    }
    if (preg_match('/\b(first|1st)\b/i', $message) && isset($properties[0])) {
        return (int)$properties[0]['property_id'];
    }
    if (preg_match('/\b(second|2nd)\b/i', $message) && isset($properties[1])) {
        return (int)$properties[1]['property_id'];
    }
    foreach ($properties as $property) {
        if (stripos($message, (string)$property['title']) !== false) {
            return (int)$property['property_id'];
        }
    }
    return null;
}

function chatbotCardData(array $properties): array
{
    return array_map(static function (array $property): array {
        $property['property_id'] = (int)$property['property_id'];
        $property['bedrooms'] = $property['bedrooms'] === null ? null : (int)$property['bedrooms'];
        $property['bathrooms'] = $property['bathrooms'] === null ? null : (int)$property['bathrooms'];
        $property['size_sqft'] = $property['size_sqft'] === null ? null : (int)$property['size_sqft'];
        $property['price'] = (float)$property['price'];
        $property['image_url'] = $property['image_url'] ? 'admin/property/' . basename($property['image_url']) : '';
        return $property;
    }, $properties);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatbotReply('Use the chat window to send a message.', [], 405);
}
$input = json_decode(file_get_contents('php://input'), true);
$message = trim((string)($input['message'] ?? ''));
if (!is_array($input) || $message === '' || mb_strlen($message, 'UTF-8') > 500) {
    chatbotReply('Please enter a message of up to 500 characters.', [], 400);
}
if (empty($input['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$input['csrf_token'])) {
    chatbotReply('Your chat session expired. Refresh the page and try again.', [], 403);
}

$state = &$_SESSION['property_chat'];
$state = is_array($state ?? null) ? $state : ['filters' => [], 'properties' => [], 'selected_id' => null];
$text = mb_strtolower($message, 'UTF-8');
$contextId = filter_var($input['property_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

try {
    $filters = chatbotFilters($message, $state['filters']);
    $isBooking = preg_match('/\b(visit|appointment|book|schedule)\b/u', $text) === 1 || !empty($state['booking']);
    $isSearch = preg_match('/\b(find|search|show|list|recommend|suggest|looking for|want a|properties|homes)\b/u', $text) === 1
        || count($filters) > count($state['filters']);

    if (!empty($state['booking']['confirm'])) {
        if (preg_match('/^\s*(yes|confirm|book it|submit)\b/i', $message)) {
            if (currentUserRole() !== 'buyer' || currentUserId() < 1) {
                chatbotReply('Please sign in with a Buyer account to submit an appointment request.', [], 200, true);
            }
            $booking = $state['booking'];
            $userId = currentUserId();
            $activeBuyer = $con->prepare("SELECT user_id FROM user WHERE user_id = ? AND LOWER(role) = 'buyer' AND is_active = 1 LIMIT 1");
            $activeBuyer->bind_param('i', $userId);
            $activeBuyer->execute();
            $buyerIsActive = (bool)$activeBuyer->get_result()->fetch_assoc();
            $activeBuyer->close();
            if (!$buyerIsActive) {
                unset($state['booking']);
                chatbotReply('Your Buyer account is not active. Sign in with an active account to request a visit.', [], 403);
            }
            if (!chatbotProperties($con, [], [$booking['property_id']])) {
                unset($state['booking']);
                chatbotReply('That property is no longer available for appointment requests.');
            }
            $duplicate = $con->prepare("SELECT appointment_id FROM appointment
                WHERE user_id = ? AND property_id = ? AND time = ? AND status <> 'Rejected' LIMIT 1");
            $duplicate->bind_param('iis', $userId, $booking['property_id'], $booking['time']);
            $duplicate->execute();
            $exists = (bool)$duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($exists) {
                unset($state['booking']);
                chatbotReply('An identical appointment request is already on file; no duplicate was created.');
            }
            $ownedProperty = $con->prepare('SELECT property_id FROM property_listings WHERE property_id = ? AND seller_id = ? LIMIT 1');
            $ownedProperty->bind_param('ii', $booking['property_id'], $userId);
            $ownedProperty->execute();
            $isOwnProperty = (bool)$ownedProperty->get_result()->fetch_assoc();
            $ownedProperty->close();
            if ($isOwnProperty) {
                unset($state['booking']);
                chatbotReply('You cannot request a visit for your own listing.');
            }
            $conflict = $con->prepare("SELECT appointment_id FROM appointment
                WHERE time = ? AND status IN ('Pending', 'Accepted')
                AND (property_id = ? OR user_id = ?) LIMIT 1");
            $conflict->bind_param('sii', $booking['time'], $booking['property_id'], $userId);
            $conflict->execute();
            $hasConflict = (bool)$conflict->get_result()->fetch_assoc();
            $conflict->close();
            if ($hasConflict) {
                unset($state['booking']);
                chatbotReply('That time conflicts with an existing appointment. Please choose another date and time.');
            }
            $insert = $con->prepare("INSERT INTO appointment (user_id, property_id, time, status)
                VALUES (?, ?, ?, 'Pending')");
            $insert->bind_param('iis', $userId, $booking['property_id'], $booking['time']);
            $insert->execute();
            $insert->close();
            unset($state['booking']);
            chatbotReply('Your property visit request has been submitted successfully. It is waiting for agent approval.');
        }
        if (preg_match('/^\s*(no|cancel)\b/i', $message)) {
            unset($state['booking']);
            chatbotReply('No problem. Your appointment request was not submitted.');
        }
        chatbotReply('Please reply “confirm” to submit this request, or “cancel” to discard it.');
    }

    if ($isBooking) {
        $id = chatbotPropertyId($message, $state['properties'], $contextId)
            ?? ($state['selected_id'] ? (int)$state['selected_id'] : null);
        if (!$id) {
            chatbotReply('Which property would you like to visit? Search for properties first, then choose a result.');
        }
        $propertyRows = chatbotProperties($con, [], [$id]);
        if (!$propertyRows) {
            chatbotReply('That property is no longer available for appointment requests.');
        }
        $requestedTime = chatbotDateTime($message);
        if (!$requestedTime && !empty($state['booking']['date'])) {
            $requestedTime = chatbotDateTime($state['booking']['date'] . ' ' . $message);
        }
        $storedTime = $requestedTime && strlen($requestedTime) > 10 ? $requestedTime : ($state['booking']['date'] ?? null);
        if ($requestedTime && strlen($requestedTime) === 10) {
            $state['booking'] = ['property_id' => $id, 'date' => $requestedTime];
            chatbotReply('What time would you prefer on ' . date('l, F j, Y', strtotime($requestedTime)) . '?');
        }
        if (!$storedTime) {
            $state['booking'] = ['property_id' => $id];
            chatbotReply('What date and time would you like to visit ' . $propertyRows[0]['title'] . '? For example, “Sunday at 3 pm”.');
        }
        if (strtotime($storedTime) <= time()) {
            chatbotReply('That date and time has already passed. Please choose a future date and time.');
        }
        $state['booking'] = ['property_id' => $id, 'time' => $storedTime, 'confirm' => true];
        chatbotReply('Please confirm: request a visit to ' . $propertyRows[0]['title'] . ' on ' .
            date('l, F j, Y \a\t g:i A', strtotime($storedTime)) . '? Reply “confirm” to submit.');
    }

    if (preg_match('/\b(compare|cheaper|cheapest|more space|larger|bigger)\b/u', $text) && count($state['properties']) >= 2) {
        $properties = $state['properties'];
        if (preg_match('/\b(space|larger|bigger)\b/u', $text)) {
            usort($properties, static fn($a, $b) => (int)$b['size_sqft'] <=> (int)$a['size_sqft']);
            chatbotReply($properties[0]['title'] . ' has ' . ((int)$properties[0]['size_sqft'] ?: 'unlisted') .
                ' sq. ft.; ' . $properties[1]['title'] . ' has ' . ((int)$properties[1]['size_sqft'] ?: 'unlisted') .
                ' sq. ft. Available area values come from the listings.', chatbotCardData(array_slice($properties, 0, 2)));
        }
        usort($properties, static fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);
        chatbotReply($properties[0]['title'] . ' is listed at ₹' . number_format((float)$properties[0]['price']) .
            ', lower than ' . $properties[1]['title'] . ' at ₹' . number_format((float)$properties[1]['price']) . '.',
            chatbotCardData(array_slice($properties, 0, 2)));
    }

    if ($isSearch) {
        if (preg_match('/\b(recommend|suggest)\b/u', $text) && !$filters) {
            chatbotReply('What location, budget, or bedroom count should I use to recommend homes? I will use only preferences you share in this chat.');
        }
        $state['filters'] = $filters;
        $properties = chatbotProperties($con, $filters);
        $state['properties'] = $properties;
        $state['selected_id'] = $properties ? (int)$properties[0]['property_id'] : null;
        if ($properties) {
            $answer = 'Here are available properties matching your preferences.';
            if (preg_match('/\b(recommend|suggest)\b/u', $text)) {
                $answer = 'These recommendations match the preferences you shared in this chat.';
            }
            $alternatives = [];
            if (!empty($filters['max_price']) && count($properties) < 3) {
                $alternatives = chatbotProperties($con, $filters, [], true);
                $alternatives = array_values(array_filter($alternatives, static fn($item) =>
                    !in_array((int)$item['property_id'], array_map(static fn($match) => (int)$match['property_id'], $properties), true)
                ));
            }
            if ($alternatives) {
                $answer .= ' You may also consider these options up to 10% above your budget, which match your other filters.';
                $properties = array_merge($properties, array_slice($alternatives, 0, 3));
            }
            if (preg_match('/\b(apartment|flat|house|villa)\b/u', $text)) {
                $answer .= ' The database does not store a separate building-style field, so results are filtered by the recorded listing details.';
            }
            chatbotReply($answer, chatbotCardData($properties));
        }
        chatbotReply('No properties match your requirements. Would you like to explore similar options?');
    }

    $id = chatbotPropertyId($message, $state['properties'], $contextId)
        ?? ($state['selected_id'] ? (int)$state['selected_id'] : null);
    if ($id) {
        $propertyRows = chatbotProperties($con, [], [$id]);
        if ($propertyRows) {
            $property = $propertyRows[0];
            if (preg_match('/\b(school|nearby|amenit|facility|facilities|transport|bus|tram|parking|pool|pet|dog|area|size|space|bedroom|bathroom|description|describe|compare|price|cost)\b/u', $text)) {
                $answer = $property['title'] . ': ';
                $facts = [];
                if (preg_match('/\b(price|cost)\b/u', $text)) {
                    $facts[] = 'listed at ₹' . number_format((float)$property['price']);
                }
                if (preg_match('/\b(area|size|space|larger)\b/u', $text)) {
                    $facts[] = $property['size_sqft'] ? number_format((int)$property['size_sqft']) . ' sq. ft.' : 'area is not listed';
                }
                if (preg_match('/\bbedroom\b/u', $text)) {
                    $facts[] = ($property['bedrooms'] ?? 'Not listed') . ' bedrooms';
                }
                if (preg_match('/\bbathroom\b/u', $text)) {
                    $facts[] = ($property['bathrooms'] ?? 'Not listed') . ' bathrooms';
                }
                if (preg_match('/\b(school|nearby|facilit|localit|amenit)\b/u', $text)) {
                    $facts[] = $property['nearest_school'] ? 'nearest school: ' . $property['nearest_school'] : 'nearby school information is not listed';
                }
                if (preg_match('/\b(bus|transport)\b/u', $text)) {
                    $facts[] = 'bus access: ' . ($property['bus_availability'] ?: 'not listed');
                    $facts[] = 'tram access: ' . ($property['tram_availability'] ?: 'not listed');
                }
                if (preg_match('/\btram\b/u', $text) && !preg_match('/\btransport\b/u', $text)) {
                    $facts[] = 'tram access: ' . ($property['tram_availability'] ?: 'not listed');
                }
                if (preg_match('/\b(pool|amenit|facilit)\b/u', $text)) {
                    $facts[] = 'pool: ' . ($property['pool_available'] ?: 'not listed');
                }
                if (preg_match('/\b(pet|dog|amenit|facilit)\b/u', $text)) {
                    $facts[] = 'pet friendly: ' . ($property['is_dog_friendly'] ?: 'not listed');
                }
                if (preg_match('/\bparking\b/u', $text)) {
                    $facts[] = 'parking information is not available in the listing data';
                }
                if (preg_match('/\b(description|describe)\b/u', $text)) {
                    $facts[] = $property['description'] ?: 'description is not listed';
                }
                if (!$facts) {
                    $facts[] = 'listed at ₹' . number_format((float)$property['price']) . ' in ' . ($property['location'] ?: 'an unlisted location');
                }
                chatbotReply($answer . implode('; ', $facts) . '.', chatbotCardData([$property]));
            }
        }
    }
    if (preg_match('/\b(favorite|favourites|favorites)\b/u', $text)) {
        chatbotReply('I only use preferences shared in this chat; I do not access your saved favorites.');
    }
    chatbotReply('I can find homes by location, sale or rent, budget, bedrooms, nearby schools, pool, or pet-friendly options. Ask about a property, compare results, or request a visit.');
} catch (Throwable $exception) {
    error_log('Property chatbot request failed: ' . $exception->getMessage());
    chatbotReply('Property search is temporarily unavailable. Please try again shortly.', [], 503);
}
