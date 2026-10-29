<?php
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
exit('Payment status cannot be confirmed here. Rental payments are currently disabled.');
