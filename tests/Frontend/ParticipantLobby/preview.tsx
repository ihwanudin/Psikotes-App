import { createInertiaApp } from '@inertiajs/react';
import ParticipantLobby from '../../../resources/js/pages/participant/lobby';
import '../../../resources/css/app.css';

// Standalone fixture: browser tests intercept both API requests before navigation.
void createInertiaApp({
    page: {
        component: 'participant/lobby',
        props: { errors: {} },
        url: '/',
        version: null,
        clearHistory: false,
        encryptHistory: false,
        rescuedProps: [],
        flash: {},
        rememberedState: {},
    },
    resolve: () => ParticipantLobby,
    strictMode: false,
    progress: false,
});
