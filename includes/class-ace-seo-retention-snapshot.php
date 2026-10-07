<?php
/** Private, immutable review snapshots. Deliberately independent of the legacy live report writer. */
final class Ace_SEO_Retention_Snapshot {
    private $directory;
    private $lock;
    private $state;

    public function __construct( $directory ) {
        if ( ! is_dir( $directory ) || is_link( $directory ) ) { throw new RuntimeException( 'Use an existing private snapshot directory.' ); }
        $this->directory = realpath( $directory );
        if ( ( fileperms( $this->directory ) & 0077 ) !== 0 ) { throw new RuntimeException( 'The snapshot directory must be private (0700).' ); }
        $this->lock = fopen( $this->directory . '/.lock', 'c' );
        if ( ! $this->lock || ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) { throw new RuntimeException( 'Another snapshot worker owns this run.' ); }
        chmod( $this->directory . '/.lock', 0600 );
        $file = $this->directory . '/state.json';
        $this->state = is_file( $file ) ? json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ) : null;
        // A crash between manifest publication and the final state checkpoint must not reopen the run.
        if ( is_file( $this->directory . '/manifest.json' ) && is_array( $this->state ) ) { $this->state['status'] = 'complete'; }
    }

    public function __destruct() {
        if ( is_resource( $this->lock ) ) { flock( $this->lock, LOCK_UN ); fclose( $this->lock ); }
    }

    private function atomic( $name, $value ) {
        $tmp = tempnam( $this->directory, '.pending-' );
        if ( false === $tmp ) { throw new RuntimeException( 'Could not create snapshot checkpoint.' ); }
        chmod( $tmp, 0600 );
        $bytes = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        if ( file_put_contents( $tmp, $bytes ) !== strlen( $bytes ) || ! rename( $tmp, $this->directory . '/' . $name ) ) {
            @unlink( $tmp ); throw new RuntimeException( 'Could not publish snapshot checkpoint.' );
        }
    }

    public function begin( array $ids, array $provenance ) {
        if ( null !== $this->state ) { throw new RuntimeException( 'This run already exists. Resume it, or use a new directory to restart.' ); }
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $id ) { return $id > 0; } ) ) );
        sort( $ids, SORT_NUMERIC );
        foreach ( array( 'rule_version', 'scope', 'as_of', 'period', 'timezone' ) as $key ) {
            if ( ! isset( $provenance[$key] ) ) { throw new InvalidArgumentException( 'Missing snapshot provenance: ' . $key ); }
        }
        $this->state = array( 'status' => 'running', 'ids' => $ids, 'offset' => 0, 'bytes' => 0, 'created_at' => gmdate( 'c' ), 'provenance' => $provenance );
        $this->atomic( 'state.json', $this->state );
        return $this->state;
    }

    public function state() { return $this->state; }

    /** Caller can resume after interruption; uncheckpointed bytes are discarded before the next batch. */
    public function append( array $records ) {
        if ( ! is_array( $this->state ) || 'running' !== $this->state['status'] ) { throw new RuntimeException( 'Only an unfinished snapshot can be appended.' ); }
        $expected = array_slice( $this->state['ids'], $this->state['offset'], count( $records ) );
        if ( $expected !== array_map( 'intval', array_column( $records, 'id' ) ) ) { throw new RuntimeException( 'Snapshot batch does not match the frozen scope and cursor.' ); }
        $path = $this->directory . '/records.jsonl';
        $fh = fopen( $path, 'c+b' );
        if ( ! $fh ) { throw new RuntimeException( 'Cannot open snapshot records.' ); }
        chmod( $path, 0600 );
        try {
            if ( fstat( $fh )['size'] < $this->state['bytes'] ) { throw new RuntimeException( 'Checkpointed snapshot data is missing; recover the original file rather than filling the gap.' ); }
            if ( ! ftruncate( $fh, $this->state['bytes'] ) || 0 !== fseek( $fh, $this->state['bytes'] ) ) { throw new RuntimeException( 'Cannot recover snapshot checkpoint.' ); }
            foreach ( $records as $record ) {
                $line = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
                if ( fwrite( $fh, $line ) !== strlen( $line ) ) { throw new RuntimeException( 'Incomplete snapshot write; resume from its checkpoint.' ); }
            }
            fflush( $fh );
            if ( function_exists( 'fsync' ) ) { fsync( $fh ); }
            $state = $this->state;
            $state['bytes'] = ftell( $fh );
            $state['offset'] += count( $records );
            $this->atomic( 'state.json', $state );
            $this->state = $state;
        } finally { fclose( $fh ); }
    }

    public function finish() {
        if ( ! is_array( $this->state ) || 'running' !== $this->state['status'] || $this->state['offset'] !== count( $this->state['ids'] ) ) { throw new RuntimeException( 'A snapshot cannot complete until its entire frozen scope is recorded.' ); }
        // Also discard any bytes written after the final durable checkpoint before hashing.
        $this->append( array() );
        $manifest = array( 'status' => 'complete', 'finished_at' => gmdate( 'c' ), 'records' => $this->state['offset'], 'sha256' => hash_file( 'sha256', $this->directory . '/records.jsonl' ), 'provenance' => $this->state['provenance'] );
        // Completion is published only after all records are durable. Failed runs never become complete.
        $this->atomic( 'manifest.json', $manifest );
        $this->state['status'] = 'complete';
        $this->atomic( 'state.json', $this->state );
        return $manifest;
    }

    /** Export is explicit and uses RAW values, with spreadsheet formula injection neutralised. */
    public function export_csv( $destination ) {
        if ( 'complete' !== ( $this->state['status'] ?? '' ) ) { throw new RuntimeException( 'Only complete snapshots can be exported.' ); }
        $manifest = json_decode( file_get_contents( $this->directory . '/manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
        if ( ! hash_equals( $manifest['sha256'], hash_file( 'sha256', $this->directory . '/records.jsonl' ) ) ) { throw new RuntimeException( 'Snapshot integrity check failed.' ); }
        $out = @fopen( $destination, 'x' );
        if ( ! $out ) { throw new RuntimeException( 'Choose a new CSV filename; existing exports are never overwritten.' ); }
        chmod( $destination, 0600 );
        $in = fopen( $this->directory . '/records.jsonl', 'rb' );
        try {
            if ( ! $in || false === fputcsv( $out, array( 'post_id', 'title', 'published', 'saved_group', 'saved_recommendation', 'saved_reason', 'saved_views', 'saved_clicks', 'saved_assessed_at', 'preview_group', 'preview_primary_suggestion', 'preview_all_suggestions', 'preview_why', 'period_start', 'period_end', 'as_of', 'rule_version' ), ',', '"', '' ) ) { throw new RuntimeException( 'Could not begin the CSV export.' ); }
            while ( false !== ( $line = fgets( $in ) ) ) {
                $record = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
                $saved = $record['baseline'] ?? array(); $preview = $record['assessment'] ?? array();
                $values = array( $record['id'], $saved['title'] ?? '', $saved['published'] ?? '', $saved['tier'] ?? '', $saved['bucket'] ?? '', $saved['reason'] ?? '', $saved['views'] ?? '', $saved['clicks'] ?? '', ! empty( $saved['built'] ) ? gmdate( 'c', $saved['built'] ) : '', $preview['tier'] ?? '', $preview['primary'] ?? '', implode( '; ', $preview['suggestions'] ?? array() ), implode( '; ', $preview['reasons'] ?? array() ), $preview['period']['start'] ?? '', $preview['period']['end'] ?? '', $preview['as_of'] ?? '', $preview['version'] ?? '' );
                $values = array_map( static function ( $value ) { $value = (string) $value; return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value; }, $values );
                if ( false === fputcsv( $out, $values, ',', '"', '' ) ) { throw new RuntimeException( 'The CSV write did not complete.' ); }
            }
            if ( ! feof( $in ) || ! fflush( $out ) || ( function_exists( 'fsync' ) && ! fsync( $out ) ) ) { throw new RuntimeException( 'The CSV export did not finish cleanly.' ); }
        } catch ( Throwable $error ) {
            // A failed partial export must not be mistaken for an existing completed CSV on resume.
            @unlink( $destination );
            throw $error;
        } finally { if ( is_resource( $in ) ) { fclose( $in ); } fclose( $out ); }
    }
}
