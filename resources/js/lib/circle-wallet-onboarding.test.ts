import { afterEach, expect, test, vi } from 'vitest';
import type { W3SSdk } from '@circle-fin/w3s-pw-web-sdk';
import {
    executeCircleChallenge,
    waitForCircleWallet,
} from './circle-wallet-onboarding';

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

test('wallet indexing is retried without creating a second wallet', async () => {
    vi.stubGlobal('window', globalThis);
    vi.useFakeTimers();
    const read = vi
        .fn()
        .mockResolvedValueOnce({ state: 'awaiting_wallet' })
        .mockResolvedValue({ address: '0x123' });
    const result = waitForCircleWallet(read, vi.fn());
    await vi.runAllTimersAsync();
    expect(await result).toBe('0x123');
    expect(read).toHaveBeenCalledTimes(2);
});

test('approval without a callback times out instead of hanging', async () => {
    vi.stubGlobal('window', globalThis);
    vi.useFakeTimers();
    const sdk = { execute: vi.fn() } as unknown as W3SSdk;
    const assertion = expect(
        executeCircleChallenge(sdk, 'challenge'),
    ).rejects.toThrow('did not complete');
    await vi.runAllTimersAsync();
    await assertion;
    expect(sdk.execute).toHaveBeenCalledTimes(1);
});
