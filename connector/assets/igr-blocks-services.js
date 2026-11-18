/*
 * Bloques «Cotizador» y «Botón de reservas» (M18) en el editor. JS plano, sin compilar:
 * marcador y ajustes en la barra lateral; el servidor pinta el slot o el enlace.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var blocks = {
		'imagina-growth/quote': {
			title: __( 'Cotizador', 'imagina-growth' ),
			icon: 'calculator',
			fields: [
				{ kind: 'text', key: 'id', label: __( 'ID de la calculadora', 'imagina-growth' ), help: __( 'Cópialo del panel: En el sitio → Cotizador (qc_…).', 'imagina-growth' ) },
				{ kind: 'number', key: 'height', label: __( 'Altura reservada (px)', 'imagina-growth' ) },
			],
		},
		'imagina-growth/booking': {
			title: __( 'Botón de reservas', 'imagina-growth' ),
			icon: 'calendar-alt',
			fields: [
				{ kind: 'text', key: 'service', label: __( 'Servicio (opcional)', 'imagina-growth' ), help: __( 'El identificador del servicio en la agenda. Vacío: la lista de servicios.', 'imagina-growth' ) },
				{ kind: 'text', key: 'label', label: __( 'Texto del botón', 'imagina-growth' ) },
				{ kind: 'select', key: 'style', label: __( 'Estilo', 'imagina-growth' ), options: [ [ 'button', __( 'Botón', 'imagina-growth' ) ], [ 'link', __( 'Enlace', 'imagina-growth' ) ] ] },
			],
		},
	};
	Object.keys( blocks ).forEach( function ( name ) {
		var b = blocks[ name ];
		if ( wp.blocks.getBlockType( name ) ) {
			return;
		}
		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title: b.title,
			icon: b.icon,
			category: 'widgets',
			supports: { html: false },
			edit: function ( props ) {
				var a = props.attributes;
				var controls = b.fields.map( function ( f ) {
					var value = a[ f.key ] === undefined || a[ f.key ] === null ? '' : a[ f.key ];
					var set = function ( v ) {
						var next = {};
						next[ f.key ] = f.kind === 'number' ? ( v === '' ? undefined : Number( v ) ) : v;
						props.setAttributes( next );
					};
					if ( f.kind === 'select' ) {
						return el( c.SelectControl, { key: f.key, label: f.label, value: value, onChange: set, options: f.options.map( function ( o ) {
							return { value: o[ 0 ], label: o[ 1 ] };
						} ) } );
					}
					return el( c.TextControl, { key: f.key, label: f.label, help: f.help, value: String( value ), type: f.kind === 'number' ? 'number' : 'text', onChange: set } );
				} );
				var summary = a.id || a.label || a.service || '';
				return el(
					'div',
					be.useBlockProps ? be.useBlockProps() : {},
					el( be.InspectorControls, null, el( c.PanelBody, { title: __( 'Ajustes', 'imagina-growth' ) }, controls ) ),
					el( c.Placeholder, { icon: b.icon, label: b.title, instructions: summary ? String( summary ) : __( 'Se llena en la tienda con los datos de Imagina Growth.', 'imagina-growth' ) } )
				);
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp );
