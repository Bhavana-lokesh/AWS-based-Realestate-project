<?php
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
exit('Public administrator registration is disabled. Provision administrator accounts through a trusted database administrator.');
