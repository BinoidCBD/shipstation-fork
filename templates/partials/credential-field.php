<?php
/**
 * Reusable ShipStation credential field row (SHIPSTN-142).
 *
 * One labelled, read-only field with optional show/hide and copy controls.
 * Used by the inline ShipStation connection section (connection-section.php) so
 * the field markup lives in one place. Pass 'value' to render the value
 * server-side; omit it to leave the input empty for JS to fill (the consumer
 * key field is left empty and revealed by JS only right after a Generate).
 *
 * @package WC_ShipStation
 *
 * @var string $field_id      Input id (also the copy/toggle data-target).
 * @var string $label         Field label text.
 * @var string $type          Input type: 'text' or 'password'.
 * @var bool   $show_toggle   Whether to render the show/hide button.
 * @var bool   $show_copy     Whether to render the copy button.
 * @var string $extra_class   Optional extra class(es) on the field root, so a caller can flatten its own wrapper onto this row instead of nesting a div.
 * @var string $wrapper_style Optional inline style on the field root (e.g. 'display: none;' for a field revealed later by JS).
 * @var string $description   Optional helper text rendered under the field.
 */

defined( 'ABSPATH' ) || exit;

// wc_get_template() extract()s these vars and include()s this file INSIDE a
// function, so the locals below are function-scoped at runtime, never real
// globals; the prefix sniff just cannot see through the include.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Field name without the trailing colon, for the icon buttons' accessible names
// (the visible <label> keeps its colon).
$shipstation_field_name = trim( rtrim( (string) $label, ' :' ) );
?>
<div class="<?php echo esc_attr( 'shipstation-auth-field' . ( ! empty( $extra_class ) ? ' ' . $extra_class : '' ) ); ?>"<?php echo ! empty( $wrapper_style ) ? ' style="' . esc_attr( (string) $wrapper_style ) . '"' : ''; ?>>
	<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
	<div class="shipstation-field-wrapper">
		<input type="<?php echo esc_attr( 'text' === $type ? 'text' : 'password' ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( isset( $value ) ? (string) $value : '' ); ?>" readonly />
		<?php
		if ( $show_toggle ) :
			/* translators: %s: credential field name, e.g. "Consumer Secret". */
			$shipstation_show_label = sprintf( __( 'Show %s', 'woocommerce-shipstation-integration' ), $shipstation_field_name );
			?>
			<button type="button" class="shipstation-toggle-visibility" data-target="<?php echo esc_attr( $field_id ); ?>" title="<?php esc_attr_e( 'Show', 'woocommerce-shipstation-integration' ); ?>" aria-label="<?php echo esc_attr( $shipstation_show_label ); ?>">
				<span class="dashicons dashicons-visibility"></span>
			</button>
		<?php endif; ?>
		<?php
		if ( $show_copy ) :
			/* translators: %s: credential field name, e.g. "Consumer Secret". */
			$shipstation_copy_label = sprintf( __( 'Copy %s to clipboard', 'woocommerce-shipstation-integration' ), $shipstation_field_name );
			?>
			<button type="button" class="shipstation-copy-btn" data-target="<?php echo esc_attr( $field_id ); ?>" title="<?php esc_attr_e( 'Copy', 'woocommerce-shipstation-integration' ); ?>" aria-label="<?php echo esc_attr( $shipstation_copy_label ); ?>">
				<span class="dashicons dashicons-admin-page"></span>
			</button>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $description ) ) : ?>
		<p class="description"><?php echo esc_html( (string) $description ); ?></p>
	<?php endif; ?>
</div>
