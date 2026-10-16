// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// https://astro.build/config
export default defineConfig({
    // GitHub Pages project site: https://koamishin.github.io/OpenSinop/
    // Change base to '' when the docs are served from a custom domain or a
    // user/organisation site (https://<user>.github.io/).
    site: 'https://koamishin.github.io',
    base: '/OpenSinop',
    integrations: [
        starlight({
            title: 'OpenSinop',
            description: 'Bounded departmental finance on Circle Agent Wallets + Arc',
            favicon: '/favicon.ico',
            customCss: ['./src/styles/custom.css'],
            disable404Route: true,
            social: [
                {
                    icon: 'github',
                    label: 'GitHub',
                    href: 'https://github.com/koamishin/OpenSinop',
                },
            ],
            editLink: {
                baseUrl:
                    'https://github.com/koamishin/OpenSinop/edit/main/docs/src/content/docs/',
            },
            sidebar: [
                {
                    label: 'Start Here',
                    items: [
                        { label: 'Overview', slug: '' },
                        {
                            label: 'Installation',
                            slug: 'getting-started/installation',
                        },
                        {
                            label: 'Configuration',
                            slug: 'getting-started/configuration',
                        },
                        {
                            label: 'Local Development',
                            slug: 'getting-started/local-development',
                        },
                    ],
                },
                {
                    label: 'Concepts',
                    items: [
                        { label: 'Core Concepts', slug: 'guides/core-concepts' },
                        { label: 'Workflows', slug: 'guides/workflows' },
                        { label: 'Architecture', slug: 'architecture' },
                    ],
                },
                {
                    label: 'For Institutions',
                    items: [
                        { label: 'Adoption Overview', slug: 'institution/overview' },
                        { label: 'Pilot Playbook', slug: 'institution/pilot-playbook' },
                        {
                            label: 'Safety & Readiness',
                            slug: 'institution/safety-readiness',
                        },
                    ],
                },
                {
                    label: 'Guides',
                    items: [
                        { label: 'Security', slug: 'guides/security' },
                        { label: 'Customization', slug: 'guides/customization' },
                        { label: 'Extensibility', slug: 'guides/extensibility' },
                        {
                            label: 'Directory Structure',
                            slug: 'guides/directory-structure',
                        },
                        { label: 'Development', slug: 'guides/development' },
                        { label: 'CI/CD & Deployment', slug: 'guides/ci-cd' },
                    ],
                },
                {
                    label: 'Reference',
                    items: [
                        { label: 'Artisan Commands', slug: 'reference/commands' },
                        { label: 'Troubleshooting', slug: 'reference/troubleshooting' },
                        { label: 'Examples', slug: 'reference/examples' },
                    ],
                },
            ],
        }),
    ],
});