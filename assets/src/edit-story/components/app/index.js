
/**
 * External dependencies
 */
import { ThemeProvider } from 'styled-components';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import {
	Popover,
	SlotFillProvider,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import Layout from '../layout';
import { APIProvider } from '../api';
import { ConfigProvider } from '../config';
import { StoryProvider } from '../story';
import { LibraryProvider } from '../library';
import darkTheme, { GlobalStyle } from '../../theme';

import './register_blocks';

function App( { config } ) {
	const { storyId } = config;
	return (
		<SlotFillProvider>
			<ThemeProvider theme={ darkTheme }>
				<ConfigProvider config={ config }>
					<APIProvider>
						<StoryProvider storyId={ storyId }>
							<LibraryProvider>
								<GlobalStyle />
								<Layout />
								<Popover.Slot />
							</LibraryProvider>
						</StoryProvider>
					</APIProvider>
				</ConfigProvider>
			</ThemeProvider>
		</SlotFillProvider>
	);
}

App.propTypes = {
	config: PropTypes.object.isRequired,
};

export default App;
