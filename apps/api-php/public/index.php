<?php

declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['message' => 'api-php scaffold']);
