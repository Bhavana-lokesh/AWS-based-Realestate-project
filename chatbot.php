<?php
// chatbot.php

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $userMessage = trim($_POST["message"] ?? '');

    if ($userMessage != '') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:5000/chatbot");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["question" => $userMessage]));

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo "Error: " . curl_error($ch);
        } else {
            $data = json_decode($response, true);
            echo htmlspecialchars($data['answer'] ?? "No response from server.");
        }

        curl_close($ch);
    } else {
        echo "Please enter a message.";
    }
}
?>
