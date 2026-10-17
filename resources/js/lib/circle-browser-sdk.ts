import browserProcess from 'vite-plugin-node-polyfills/shims/process';

/** Install browser-only runtime before evaluating Circle's Node-oriented dependencies. */
export async function loadCircleSdk() {
    if (typeof window === 'undefined') {
        throw new Error('Circle wallet approval requires a browser.');
    }
    const runtime = globalThis as unknown as {
        process?: typeof browserProcess;
    };
    runtime.process ??= browserProcess;

    return import('@circle-fin/w3s-pw-web-sdk');
}
