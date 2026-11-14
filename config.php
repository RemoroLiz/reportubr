<?php
// ===== Konfigurasi =====
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');

const APP_NAME = 'Pantes Sales Report';

date_default_timezone_set('Asia/Jakarta');

const DEPT_DIAMOND = [
    'BA',
    'BB',
    'BC',
    'BCK',
    'BGK',
    'BGR',
    'BK'
];

const DEPT_NON_PCS = [
    'KOTAK',
    'KOTAK LM',
    'SERTIFIKAT'
];

const MAX_UPLOAD_MB = 20;

const GENERIC_CUSTOMER_NAMES = [
    '',
    '-',
    'REGULER',
    'UMUM',
    'CASH',
    'CUSTOMER',
    'TANPA NAMA'
];
