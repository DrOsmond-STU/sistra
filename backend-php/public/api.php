<?php
// Front controller SISTRA (pengembangan lokal). Di hosting, salin dan sesuaikan dua baris path di bawah.
define('SISTRA_DATA_DIR', getenv('SISTRA_DATA_DIR') ?: __DIR__ . '/../data');
require __DIR__ . '/../src/app.php';
sistra_handle();
