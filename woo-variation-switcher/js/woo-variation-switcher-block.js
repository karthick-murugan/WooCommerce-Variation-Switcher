/* global wooVariationSwitcherParams */
const { registerCheckoutFilters } = window.wc.blocksCheckout;

const editVariableProduct = ( defaultValue, extensions, args ) => {
	const isVariableItem = args?.cartItem?.type === 'variation';
	const variationId = args?.cartItem?.id; // This is the variation ID

	if ( isVariableItem && variationId ) {
		return `
            <a href="${ args?.cartItem?.permalink }">${ args?.cartItem?.name }</a>
            <br />
            <a id="${ variationId }" data-item-url="${ args?.cartItem?.permalink }" class="variation-switcher">Edit</a>
        `;
	}
	return `<a href="${ args?.cartItem?.permalink }">${ args?.cartItem?.name }</a>`;
};

registerCheckoutFilters( 'example-extension', {
	itemName: editVariableProduct,
} );

jQuery( document ).ready( function ( $ ) {
	$( document ).on( 'click', '.variation-switcher', function ( e ) {
		e.preventDefault();
		const variationId = $( this ).attr( 'id' );

		// AJAX call to retrieve variations
		$.ajax( {
			url: wooVariationSwitcherParams.ajax_url,
			type: 'POST',
			data: {
				action: 'woo_variation_switcher_get_variations',
				variation_id: variationId,
				security: wooVariationSwitcherParams.nonce,
			},
			success( response ) {
				if ( response.success ) {
					const attributes = response.data.attributes;
					const cartItem = response.data.cart_item;
					const productId = response.data.product_id; // Get product_id from response

					// Set product_id in hidden input
					const hiddenInput = $( '<input>' ).attr( {
						type: 'hidden',
						id: 'product_id',
						value: productId,
					} );
					$( '#variation-switcher-popup' ).append( hiddenInput ); // phpcs:ignore

					// Clear previous select boxes and labels
					const selectsContainer = $(
						'#variation-switcher-selects-container'
					);
					selectsContainer.empty();
					// Populate select boxes with variations and pre-select options
					$.each( attributes, function ( attrName, attrOptions ) {
						const selectBox = $( '<select></select>' ).attr(
							'id',
							'variation-switcher-' + attrName
						);
						const variationKey = attrName.startsWith( 'pa_' )
							? 'attribute_' + attrName
							: 'attribute_' + attrName.toLowerCase(); // Adjust the key based on your attribute naming convention

						$.each( attrOptions, function ( index, option ) {
							const selected =
								cartItem &&
								cartItem.variation[ variationKey ] ===
									option.value
									? 'selected'
									: '';
							const optionLabel = attrName.startsWith( 'pa_' )
								? option.label.replace( attrName + ': ', '' )
								: option.label; // Adjust label display based on attribute name
							const optionElement = $( '<option></option>' )
								.attr( 'value', option.value )
								.text( optionLabel );
							if ( selected ) {
								optionElement.attr( 'selected', 'selected' );
							}
							selectBox.append( optionElement ); // phpcs:ignore
						} );

						const label = $( '<label></label>' ).text(
							getOptionLabel( attrName )
						);
						selectsContainer.append( label ); // phpcs:ignore
						selectsContainer.append( selectBox ); // phpcs:ignore
					} );

					// Show the popup
					$( '#variation-switcher-overlay' ).show();
				} else {
					displayError( response.data );
				}
			},
			error() {
				displayError(
					'Error retrieving variations. Please try again.'
				);
			},
		} );
	} );

	// Function to get user-friendly option label based on attribute name
	function getOptionLabel( attrName ) {
		// Example logic: Remove 'pa_' and capitalize first letter
		let label = attrName.replace( 'pa_', '' ); // Remove 'pa_' prefix
		label = label.charAt( 0 ).toUpperCase() + label.slice( 1 ); // Capitalize first letter
		return label;
	}

	// Close popup when clicking outside of it
	$( document ).on( 'click', '#variation-switcher-overlay', function ( e ) {
		if ( e.target === this ) {
			$( this ).hide();
		}
	} );

	// Function to handle the update button click
	function handleUpdateButtonClick() {
		const selectedVariations = {};

		$( '#variation-switcher-popup select' ).each( function () {
			const selectElement = $( this );
			const id = selectElement.attr( 'id' );
			const attribute = id.replace( 'variation-switcher-', '' ); // Extract attribute name
			const value = selectElement.val(); // Get selected value

			// Ensure prefix 'attribute_' is added and attribute name is lowercase
			if ( attribute && value ) {
				selectedVariations[ 'attribute_' + attribute.toLowerCase() ] =
					value;
			}
		} );

		if ( $.isEmptyObject( selectedVariations ) ) {
			displayError( 'No variations selected' );
			return;
		}

		const productId = $( '#product_id' ).val();

		$.ajax( {
			url: wooVariationSwitcherParams.ajax_url,
			method: 'POST',
			data: {
				action: 'update_variation',
				product_id: productId,
				variations: selectedVariations,
				security: wooVariationSwitcherParams.nonce,
			},
			success( response ) {
				if ( response.success ) {
					// Update cart or relevant elements with new data
					setTimeout( () => {
						window.location.reload(); // Reload the page to reflect changes
					}, 2000 );
				} else {
					displayError( response.data );
				}
			},
			error() {
				displayError( 'Error updating variation. Please try again.' );
			},
		} );
	}

	$( '#update-variations-button' ).on( 'click', handleUpdateButtonClick );

	function displayError( message ) {
		const errorDiv = $( '<div></div>' )
			.addClass( 'variation-switcher-error' )
			.text( message );
		$( '#variation-switcher-popup' ).append( errorDiv ); // phpcs:ignore
		setTimeout( function () {
			$( '.variation-switcher-error' ).fadeOut( 'slow', function () {
				$( this ).remove();
			} );
		}, 5000 ); // Remove error message after 5 seconds
	}
} );
