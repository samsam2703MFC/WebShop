<?php
/* Reprise de la remontée des commandes vers l'ERP (client_order).
 *
 * Le chemin NOMINAL est synchrone : POST /orders (comptoir, sur-compte) et le
 * webhook Stripe (payé) appellent erp_order_push() eux-mêmes. Ce cron ne fait
 * que RATTRAPER : ERP en panne au moment T, timeout, processus mort après le
 * commit — tout ce qui a laissé erp_order_id à NULL sur une commande pourtant
 * définitive.
 *
 *   php php-api/cron/erp-orders-push.php
 *   crontab (toutes les 5 minutes) :
 *     php /var/www/atelierby/api/cron/erp-orders-push.php
 *   (voir ERP_SYNC.md §2 — remplace l'ancien plan « outbox »)
 *
 * Fenêtres, et pourquoi :
 *  - delivery_date >= aujourd'hui : pousser une commande déjà servie ferait
 *    PRODUIRE la boutique pour rien — l'ERP n'a pas de statut « annulée » pour
 *    la retirer (statuts confirmés le 31/08 : new/completed/picked_up/
 *    unpicked) ;
 *  - créée il y a moins de 14 jours : borne de sûreté à l'activation du
 *    paramètre — on ne déverse pas l'historique ;
 *  - échec déjà enregistré OU plus de 15 minutes d'âge : la tentative
 *    synchrone a eu sa chance ; la fenêtre réduit la course avec elle, le
 *    verrou GET_LOCK dans erp_order_push() élimine le reste.
 */
require __DIR__ . '/../lib.php';
require __DIR__ . '/../erp_alias.php';
require __DIR__ . '/../erp_orders.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("cli seulement\n"); }
if (!erp_orders_enabled()) { echo "remontée inactive (ws_param.orders_push ≠ 'erp')\n"; exit(0); }
if (!col_exists('ws_orders', 'erp_order_id')) { echo "migration 0104 absente\n"; exit(0); }

$cands = rows("SELECT id, order_ref, payment_method, payment_status FROM ws_orders
                WHERE erp_order_id IS NULL
                  AND customer_id IS NOT NULL
                  AND status <> 'cancelled'
                  AND delivery_date >= CURDATE()
                  AND created_at >= NOW() - INTERVAL 14 DAY
                  AND (erp_push_error IS NOT NULL OR created_at < NOW() - INTERVAL 15 MINUTE)
                ORDER BY id");

$ok = 0; $ko = 0; $attente = 0;
foreach ($cands as $c) {
  // Même règle que les déclencheurs synchrones : une commande Stripe n'est
  // définitive qu'ENCAISSÉE — avant, elle attend (ou meurt abandonnée).
  if (erp_order_famille($c['payment_method']) === 'stripe' && ($c['payment_status'] ?? '') !== 'paid') {
    $attente++;
    continue;
  }
  if (erp_order_push((int) $c['id'])) { $ok++; echo "✓ {$c['order_ref']}\n"; }
  else                                { $ko++; echo "✗ {$c['order_ref']} (voir erp_push_error)\n"; }
}
echo "remontée ERP : $ok poussée(s), $ko échec(s), $attente en attente d'encaissement.\n";
