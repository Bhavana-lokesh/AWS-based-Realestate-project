<?php
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
exit('Sale payments are disabled until a server-verified payment workflow is configured.');
