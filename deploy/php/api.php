<?php
// Front controller SISTRA di root dokumen. Kode aplikasi dan data berada DI LUAR root dokumen.
define('SISTRA_DATA_DIR', dirname(__DIR__) . '/sistra-data');
require dirname(__DIR__) . '/sistra-api/src/app.php';
sistra_handle();
