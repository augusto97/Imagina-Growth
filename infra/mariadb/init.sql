-- Bases de desarrollo, pruebas y la tienda de prueba.
CREATE DATABASE IF NOT EXISTS igr_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_igr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_igr_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON igr_test.* TO 'igr'@'%';
GRANT ALL ON wp_igr.* TO 'igr'@'%';
GRANT ALL ON wp_igr_tests.* TO 'igr'@'%';
