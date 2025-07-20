// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// https://astro.build/config
export default defineConfig({
	integrations: [
		starlight({
			title: 'Le Chat',
			social: [{ icon: 'github', label: 'GitHub', href: 'https://github.com/M-Media-Group/laravel-chat' }],
			sidebar: [
				{
					label: 'Getting Started',
					items: [
						// Each item here is one entry in the navigation menu.
						{ label: 'Installation', slug: 'installation' },
						{ label: 'Configuring models', slug: 'configuring-models' },
						{ label: 'Your first conversation', slug: 'your-first-conversation' },
						{ label: 'Architecture concepts', slug: 'architecture-concepts' },
					],
				},
				{
					label: 'The Basics',
					items: [
						// Each item here is one entry in the navigation menu.
						{ label: 'Participants', slug: 'participants' },
						{ label: 'Chatrooms', slug: 'chatrooms' },
						{ label: 'Messages', slug: 'messages' },
					],
				},
				{
					label: 'Digging Deeper',
					items: [
						{ label: 'Events', slug: 'events' },
						// { label: 'Listeners', slug: 'broadcasting' },
						{ label: 'Broadcasting', slug: 'broadcasting' },
						{ label: 'Notifications', slug: 'notifications' },
						{ label: 'Routing', slug: 'routing' },
						// { label: 'Resources', slug: 'package-configuration' },
						{ label: 'Package Configuration', slug: 'package-configuration' },
					],
				},
				{
					label: 'Reference',
					autogenerate: { directory: 'reference' },
				},
			],
		}),
	],
});
