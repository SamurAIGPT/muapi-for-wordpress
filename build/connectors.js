import { __experimentalRegisterConnector as registerConnector } from '@wordpress/connectors';
import { __ } from '@wordpress/i18n';

registerConnector( 'muapi', {
    name: 'MuAPI',
    description: __( 'MuAPI generative media aggregator (image and video models).', 'muapi-for-wordpress' ),
    logo: 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="20" fill="%230f172a"/><text x="50" y="62" font-size="45" font-weight="bold" fill="%2338bdf8" font-family="system-ui, sans-serif" text-anchor="middle">μ</text></svg>',
} );
