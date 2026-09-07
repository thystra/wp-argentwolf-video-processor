# Sourced by the exact-export administrator smoke after backend activation.
AWVP_R45_CRON_ASSERT="/var/www/html/wp-content/plugins/argentwolf-video-processor/$FIXTURE_RELATIVE/assert-cron-wiring.php"
wp_cli --context=cli eval-file "$AWVP_R45_CRON_ASSERT" --use-include
unset AWVP_R45_CRON_ASSERT
