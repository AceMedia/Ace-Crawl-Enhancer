/**
 * Ace SEO: Readers panel in the block editor's document sidebar (retention report posts only).
 *
 * Loads nothing until asked: one request to ace-seo/v1/retention/audience, then people against bots
 * per day as a small line chart, referrers by source and host, posts on the site linking here, and
 * Google Analytics sources where Site Kit has them. Plain wp.element, no build step.
 */
( function ( wp, cfg ) {
	if ( ! wp || ! cfg || ! wp.plugins || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	if ( ! Panel ) {
		return;
	}

	var SOURCE_LABELS = { search: 'Search', social: 'Social', site: 'Other sites', internal: 'This site', direct: 'Direct or unknown' };

	function Chart( props ) {
		var series = props.series || [];
		if ( ! series.length ) {
			return null;
		}
		var w = 248, h = 90, pad = 4;
		var max = 1;
		series.forEach( function ( d ) { max = Math.max( max, d.humans, d.bots ); } );
		var x = function ( i ) { return pad + ( i * ( w - 2 * pad ) ) / Math.max( 1, series.length - 1 ); };
		var y = function ( v ) { return h - pad - ( v * ( h - 2 * pad ) ) / max; };
		var line = function ( key ) {
			return series.map( function ( d, i ) { return x( i ).toFixed( 1 ) + ',' + y( d[ key ] ).toFixed( 1 ); } ).join( ' ' );
		};
		return el( 'figure', { style: { margin: '8px 0' } },
			el( 'svg', { width: '100%', viewBox: '0 0 ' + w + ' ' + h, role: 'img', 'aria-label': 'People and bots per day, last ' + series.length + ' days' },
				el( 'line', { x1: pad, x2: w - pad, y1: h - pad, y2: h - pad, stroke: '#ddd' } ),
				el( 'polyline', { points: line( 'bots' ), fill: 'none', stroke: '#b32d2e', strokeWidth: 1.5 } ),
				el( 'polyline', { points: line( 'humans' ), fill: 'none', stroke: '#2271b1', strokeWidth: 2 } )
			),
			el( 'figcaption', { style: { fontSize: '11px', color: '#646970' } },
				el( 'span', { style: { color: '#2271b1', fontWeight: 600 } }, 'People' ), ' and ',
				el( 'span', { style: { color: '#b32d2e', fontWeight: 600 } }, 'bots' ),
				' per day, ' + series[ 0 ].day + ' to ' + series[ series.length - 1 ].day + ' (peak ' + max + ')'
			)
		);
	}

	function List( props ) {
		if ( ! props.items || ! props.items.length ) {
			return el( 'p', { style: { color: '#646970' } }, props.empty );
		}
		return el( 'ul', { style: { margin: '4px 0 12px', paddingLeft: '1.2em', listStyle: 'disc' } }, props.items.map( props.render ) );
	}

	function Readers() {
		var state = useState( null );
		var data = state[ 0 ], setData = state[ 1 ];
		var busy = useState( false );
		var err = useState( '' );

		var load = function () {
			busy[ 1 ]( true );
			err[ 1 ]( '' );
			wp.apiFetch( { path: '/ace-seo/v1/retention/audience?post=' + cfg.postId } )
				.then( function ( d ) { setData( d ); } )
				.catch( function ( e ) { err[ 1 ]( ( e && e.message ) || 'Could not load.' ); } )
				.then( function () { busy[ 1 ]( false ); } );
		};

		var children = [ el( 'p', { key: 'tier' }, el( 'strong', null, cfg.tierLabel ) ) ];

		if ( ! data ) {
			children.push( el( 'p', { key: 'intro', style: { color: '#646970' } }, 'Who reads this post and where they come from. Loaded on request.' ) );
			children.push( el( Button, { key: 'load', variant: 'secondary', onClick: load, disabled: busy[ 0 ] }, busy[ 0 ] ? 'Loading…' : 'Load readers and referrers' ) );
			if ( busy[ 0 ] ) {
				children.push( el( Spinner, { key: 'spin' } ) );
			}
		} else {
			if ( ! data.tracking ) {
				children.push( el( 'p', { key: 'nt', style: { color: '#8a6d00' } }, 'Own tracking is off (Ace SEO, Retention, Report settings), so there is no people or bot count yet.' ) );
			} else {
				children.push( el( 'p', { key: 'sum' },
					data.humans + ' people, ' + data.bots + ' bots in ' + data.days + ' days' + ( null !== data.bot_pct ? ' (' + data.bot_pct + '% bots)' : '' ) ) );
				children.push( el( Chart, { key: 'chart', series: data.series } ) );
				children.push( el( 'h3', { key: 'h-src', style: { fontSize: '12px', margin: '12px 0 4px' } }, 'Where people came from' ) );
				children.push( el( List, {
					key: 'src',
					items: Object.keys( data.sources || {} ),
					empty: 'No referrers recorded yet.',
					render: function ( s ) { return el( 'li', { key: s }, ( SOURCE_LABELS[ s ] || s ) + ': ' + data.sources[ s ] ); },
				} ) );
				children.push( el( List, {
					key: 'refs',
					items: ( data.referrers || [] ).filter( function ( r ) { return r.host; } ).slice( 0, 15 ),
					empty: '',
					render: function ( r ) { return el( 'li', { key: r.source + r.host }, r.host + ' (' + ( SOURCE_LABELS[ r.source ] || r.source ) + '): ' + r.hits + ', last ' + r.last ); },
				} ) );
			}
			if ( data.analytics && data.analytics.available ) {
				children.push( el( 'h3', { key: 'h-ga', style: { fontSize: '12px', margin: '12px 0 4px' } }, 'Google Analytics, last ' + data.analytics.days + ' days' ) );
				children.push( el( List, {
					key: 'ga',
					items: data.analytics.rows,
					empty: 'No views recorded in Analytics.',
					render: function ( r, i ) { return el( 'li', { key: i }, r.source + ' / ' + r.medium + ': ' + r.views ); },
				} ) );
			}
			children.push( el( 'h3', { key: 'h-links', style: { fontSize: '12px', margin: '12px 0 4px' } }, 'Posts on this site linking here' ) );
			children.push( el( List, {
				key: 'links',
				items: data.links_in,
				empty: 'None found: this post is orphaned.',
				render: function ( l ) { return el( 'li', { key: l.id }, el( 'a', { href: l.url, target: '_blank', rel: 'noopener noreferrer' }, l.title || '#' + l.id ), ' (' + l.date + ')' ); },
			} ) );
			children.push( el( Button, { key: 'reload', variant: 'link', onClick: load, disabled: busy[ 0 ] }, busy[ 0 ] ? 'Loading…' : 'Refresh' ) );
		}
		if ( err[ 0 ] ) {
			children.push( el( 'p', { key: 'err', style: { color: '#b32d2e' } }, err[ 0 ] ) );
		}

		return el( Panel, { name: 'ace-seo-readers', title: 'Readers (Ace SEO)', className: 'ace-seo-readers-panel' }, children );
	}

	wp.plugins.registerPlugin( 'ace-seo-readers', { render: Readers, icon: null } );
} )( window.wp, window.aceSeoRetentionPanel );
