#!/bin/sh
# Instala WordPress + WooCommerce + el connector y genera la tienda demo (desarrollo local, infra/dev/README.md).
# Se corre con WP-CLI en la carpeta de WordPress (o con WP_CLI_PATH apuntando a ella):
#   cd /ruta/wordpress && sh /ruta/Imagina-Growth/infra/wordpress/igr-setup.sh [pedidos] [clientes]
set -e
ORDERS=${1:-10000}
CUSTOMERS=${2:-2000}
if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8080 --title="Pinturas La 14 (prueba)" --admin_user=admin \
    --admin_password=admin --admin_email=admin@tienda.test --skip-email
  wp language core install es_CO --activate || true
  wp option update timezone_string America/Bogota
fi
wp plugin install woocommerce --activate
wp option update woocommerce_currency COP
wp option update woocommerce_default_country CO:CAL
wp plugin activate imagina-growth-connector
wp igr demo-store --customers="$CUSTOMERS" --orders="$ORDERS"
wp igr status
