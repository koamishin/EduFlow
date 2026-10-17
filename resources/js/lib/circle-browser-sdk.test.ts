import { afterEach, expect, test, vi } from 'vitest';
import { loadCircleSdk } from './circle-browser-sdk';

vi.mock('@circle-fin/w3s-pw-web-sdk', () => {
    if (typeof window === 'undefined' || !('process' in globalThis)) {
        throw new Error('process is not defined');
    }
    return { W3SSdk: class {} };
});

afterEach(() => vi.unstubAllGlobals());

test('installs process before evaluating the Circle SDK in browsers', async () => {
    vi.stubGlobal('window', {});
    vi.stubGlobal('process', undefined);
    const sdk = await loadCircleSdk();
    expect(sdk.W3SSdk).toBeTypeOf('function');
    expect(
        (globalThis as unknown as { process: { browser: boolean } }).process
            .browser,
    ).toBe(true);
});

test('does not load browser SDK during server rendering', async () => {
    vi.stubGlobal('window', undefined);
    await expect(loadCircleSdk()).rejects.toThrow('requires a browser');
});
