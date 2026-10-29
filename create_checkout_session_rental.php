<?php
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
exit('Rental payments are disabled until the rental payment terms and server verification are configured.');
