<?php
if ( PHP_SAPI !== 'cli' ) {
    exit;
}
require_once dirname( __DIR__ ) . '/includes/class-ace-seo-seasonal-window.php';
$checks = 0;
function check_window( $publication, $as_of, $expected ) {
    global $checks;
    $actual = Ace_SEO_Seasonal_Window::preview( $publication, $as_of, new DateTimeZone( 'Europe/London' ) );
    foreach ( $expected as $key => $value ) {
        ++$checks;
        if ( $actual[$key] !== $value ) {
            throw new RuntimeException( "$publication / $as_of: $key: " . json_encode( $actual ) );
        }
    }
}
check_window( '2020-03-15', '2026-10-07', array( 'in_season' => false, 'season_start' => '2027-01-15', 'season_end' => '2027-05-15' ) );
check_window( '2020-03-15', '2026-01-15', array( 'in_season' => true ) );
check_window( '2020-03-15', '2026-05-15', array( 'in_season' => true ) );
check_window( '2020-03-15', '2026-05-16', array( 'in_season' => false ) );
check_window( '2020-01-31', '2025-12-01', array( 'in_season' => true, 'season_start' => '2025-11-30', 'season_end' => '2026-03-31' ) );
check_window( '2020-12-31', '2026-01-01', array( 'in_season' => true, 'anniversary' => '2025-12-31', 'season_end' => '2026-02-28' ) );
check_window( '2020-02-29', '2026-02-28', array( 'in_season' => true, 'anniversary' => '2026-02-28' ) );
check_window( '2020-02-29', '2028-02-29', array( 'anniversary' => '2028-02-29', 'season_start' => '2027-12-29' ) );
check_window( '2020-08-31', '2026-10-07', array( 'season_start' => '2026-06-30', 'season_end' => '2026-10-31', 'timezone' => 'Europe/London' ) );
foreach ( array( array( '2026-02-30', '2026-10-07' ), array( '2027-01-01', '2026-10-07' ), array( '2020-01-01', 'tomorrow' ), array( '0000-00-00', '2026-10-07' ) ) as $invalid ) {
    try {
        Ace_SEO_Seasonal_Window::preview( $invalid[0], $invalid[1], new DateTimeZone( 'UTC' ) );
        throw new RuntimeException( 'Invalid date accepted.' );
    } catch ( InvalidArgumentException $expected ) {
        ++$checks;
    }
}
echo "$checks seasonal window checks passed.\n";
