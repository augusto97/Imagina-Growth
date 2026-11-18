/*
 * Bloques de Imagina Growth en el editor (connector/CLAUDE.md §10). JS plano, sin compilar:
 * cada bloque muestra un marcador y sus ajustes en la barra lateral; el servidor pinta el
 * slot y el tracker lo llena en la tienda.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var text = function ( label, key, help ) {
		return { kind: 'text', label: label, key: key, help: help };
	};
	var select = function ( label, key, options ) {
		return { kind: 'select', label: label, key: key, options: options };
	};
	var number = function ( label, key ) {
		return { kind: 'number', label: label, key: key };
	};
	var blocks = {
		'imagina-growth/form': {
			title: __( 'Formulario de Imagina Growth', 'imagina-growth' ),
			icon: 'feedback',
			fields: [ text( __( 'ID del widget', 'imagina-growth' ), 'id', __( 'Cópialo del panel de Imagina Growth (wg_…).', 'imagina-growth' ) ), number( __( 'Altura reservada (px)', 'imagina-growth' ), 'height' ) ],
		},
		'imagina-growth/quiz': {
			title: __( 'Quiz de Imagina Growth', 'imagina-growth' ),
			icon: 'editor-help',
			fields: [ text( __( 'ID del quiz', 'imagina-growth' ), 'id', __( 'Cópialo del panel de Imagina Growth (wg_…).', 'imagina-growth' ) ), number( __( 'Altura reservada (px)', 'imagina-growth' ), 'height' ) ],
		},
		'imagina-growth/recommendations': {
			title: __( 'Recomendaciones', 'imagina-growth' ),
			icon: 'products',
			fields: [
				select( __( 'Tipo', 'imagina-growth' ), 'type', [
					[ 'para_ti', __( 'Para ti', 'imagina-growth' ) ],
					[ 'bought_together', __( 'Se compran juntos', 'imagina-growth' ) ],
					[ 'recently_viewed', __( 'Vistos recientemente', 'imagina-growth' ) ],
					[ 'best_sellers_category', __( 'Más vendidos de la categoría', 'imagina-growth' ) ],
					[ 'trending', __( 'En tendencia', 'imagina-growth' ) ],
					[ 'complete_cart', __( 'Completa tu compra', 'imagina-growth' ) ],
				] ),
				text( __( 'Producto (ID, opcional)', 'imagina-growth' ), 'product_id' ),
				text( __( 'Categoría (ID, opcional)', 'imagina-growth' ), 'category_id' ),
				number( __( 'Cantidad', 'imagina-growth' ), 'limit' ),
				text( __( 'Título (opcional)', 'imagina-growth' ), 'title' ),
			],
		},
		'imagina-growth/reviews': {
			title: __( 'Reseñas', 'imagina-growth' ),
			icon: 'star-filled',
			fields: [
				select( __( 'Diseño', 'imagina-growth' ), 'layout', [
					[ 'list', __( 'Lista', 'imagina-growth' ) ],
					[ 'carousel', __( 'Carrusel', 'imagina-growth' ) ],
					[ 'gallery', __( 'Galería de fotos', 'imagina-growth' ) ],
					[ 'badge', __( 'Insignia de estrellas', 'imagina-growth' ) ],
				] ),
				text( __( 'Producto (ID, opcional)', 'imagina-growth' ), 'product_id', __( 'Vacío: el de la ficha o las reseñas de la tienda.', 'imagina-growth' ) ),
				text( __( 'Título (opcional)', 'imagina-growth' ), 'title' ),
			],
		},
		'imagina-growth/loyalty': {
			title: __( 'Mis recompensas', 'imagina-growth' ),
			icon: 'awards',
			fields: [],
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
				var summary = a.id || a.type || a.layout || '';
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
