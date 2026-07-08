<?php
/**
 * Real pickup availability — days and time-slots generated from the shop's
 * opening hours (stored as a plugin option, editable), replacing the demo
 * slots. Applies a same-day cutoff so past slots aren't bookable.
 *
 * Opening hours option `atelier_opening_hours`:
 *   { "1": {"open":"08:00","close":"18:00"}, ..., "0": null }   // 0=Sun..6=Sat
 * null = closed that weekday. Defaults below (bakery: Tue–Sun, closed Mon).
 */
if (!defined('ABSPATH')) exit;

class Atelier_Availability {

    const HOURS_OPT    = 'atelier_opening_hours';
    const CUTOFF_OPT   = 'atelier_collect_cutoff';  // "HH:MM" — no same-day pickup after this
    const SLOT_MIN     = 30;                         // slot length in minutes
    const CAPACITY     = 8;                          // orders per slot (display only for now)
    const HORIZON_DAYS = 14;

    private static function default_hours(): array {
        $wk = ['open' => '08:00', 'close' => '18:00'];
        return ['0' => $wk, '1' => null, '2' => $wk, '3' => $wk, '4' => $wk, '5' => $wk, '6' => $wk];
    }

    public static function hours(): array {
        $h = get_option(self::HOURS_OPT);
        return is_array($h) && $h ? $h : self::default_hours();
    }

    private static function cutoff_str(): string {
        return (string) (get_option(self::CUTOFF_OPT) ?: '16:00');
    }

    /* WSAvailability.getShopSettings */
    public static function settings(\WP_REST_Request $req) {
        $hours = self::hours();
        $open_days = array_values(array_filter(array_keys($hours), fn($d) => !empty($hours[$d])));
        [$ch, $cm] = array_map('intval', explode(':', self::cutoff_str()));
        return rest_ensure_response([
            'open_days'      => array_map('intval', $open_days),
            'opening_hours'  => $hours,
            'collect_cutoff' => ['hour' => $ch, 'minutes' => $cm],
            'slot_minutes'   => self::SLOT_MIN,
        ]);
    }

    /* WSCalendar.getCutoff */
    public static function cutoff_endpoint(\WP_REST_Request $req) {
        [$h, $m] = array_map('intval', explode(':', self::cutoff_str()));
        return rest_ensure_response(['hour' => $h, 'minutes' => $m]);
    }

    /* Available days over the horizon (WSAvailability.listAvailableDays). */
    public static function days(\WP_REST_Request $req) {
        $hours = self::hours();
        $tz    = wp_timezone();
        $today = new \DateTime('today', $tz);
        $out   = [];
        for ($i = 0; $i < self::HORIZON_DAYS; $i++) {
            $d    = (clone $today)->modify("+$i day");
            $dow  = (string) ((int) $d->format('w')); // 0=Sun..6=Sat
            $open = !empty($hours[$dow]);
            $out[] = [
                'iso'       => $d->format('Y-m-d'),
                'available' => $open,
                'reason'    => $open ? null : 'closed',
                'type'      => $open ? 'open' : 'closed',
            ];
        }
        return rest_ensure_response($out);
    }

    /* Time-slots for a given date (WSAvailability.listSlots). */
    public static function slots(\WP_REST_Request $req) {
        $date  = $req->get_param('date');
        $hours = self::hours();
        $tz    = wp_timezone();
        try { $d = new \DateTime($date ?: 'today', $tz); }
        catch (\Exception $e) { return rest_ensure_response([]); }

        $dow = (string) ((int) $d->format('w'));
        if (empty($hours[$dow])) return rest_ensure_response([]); // closed that day

        $now     = new \DateTime('now', $tz);
        $isToday = $d->format('Y-m-d') === (new \DateTime('today', $tz))->format('Y-m-d');
        [$ch, $cm] = array_map('intval', explode(':', self::cutoff_str()));
        $cutoffPassed = $isToday && ((int) $now->format('H') * 60 + (int) $now->format('i')) >= ($ch * 60 + $cm);

        $start = \DateTime::createFromFormat('Y-m-d H:i', $d->format('Y-m-d') . ' ' . $hours[$dow]['open'], $tz);
        $end   = \DateTime::createFromFormat('Y-m-d H:i', $d->format('Y-m-d') . ' ' . $hours[$dow]['close'], $tz);
        if (!$start || !$end) return rest_ensure_response([]);

        $out = [];
        for ($t = clone $start; $t < $end; $t->modify('+' . self::SLOT_MIN . ' minutes')) {
            $slotEnd = (clone $t)->modify('+' . self::SLOT_MIN . ' minutes');
            $past    = $isToday && $t <= $now;
            $blocked = $cutoffPassed || $past;
            $out[] = [
                'id'             => $t->format('H:i'),
                'label'          => $t->format('H:i') . ' – ' . $slotEnd->format('H:i'),
                'capacity'       => self::CAPACITY,
                'current_orders' => 0,
                'available'      => !$blocked,
                'reason'         => $cutoffPassed ? 'cutoff_passed' : ($past ? 'past' : null),
            ];
        }
        return rest_ensure_response($out);
    }
}
