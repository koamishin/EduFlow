import type { W3SSdk } from '@circle-fin/w3s-pw-web-sdk';

/** SDK modal close does not always invoke its callback. Never wait indefinitely. */
export function executeCircleChallenge(
    sdk: W3SSdk,
    challengeId: string,
): Promise<void> {
    return new Promise((resolve, reject) => {
        const timer = window.setTimeout(
            () =>
                reject(
                    new Error(
                        'Circle approval did not complete. Reconnect the same wallet to resume; no payment was automatically resent.',
                    ),
                ),
            120_000,
        );
        sdk.execute(challengeId, (error, result) => {
            window.clearTimeout(timer);
            if (error) {
                reject(
                    new Error(
                        `Circle approval failed (${error.code}): ${error.message}`,
                    ),
                );
                return;
            }
            if (result && 'status' in result && result.status !== 'COMPLETE') {
                reject(
                    new Error(
                        `Circle challenge is ${result.status}. Finish approval before continuing.`,
                    ),
                );
                return;
            }
            resolve();
        });
    });
}

/** Challenge success precedes wallet indexing. Retry reads only, never creation or transfers. */
export async function waitForCircleWallet(
    read: () => Promise<{ address?: string; state?: string; message?: string }>,
    progress: (message: string) => void,
): Promise<string> {
    for (let attempt = 0; attempt < 12; attempt++) {
        const wallet = await read();
        if (wallet.address) return wallet.address;
        if (wallet.state !== 'awaiting_wallet')
            throw new Error(
                wallet.message ?? 'Circle did not return a linked wallet.',
            );
        progress(
            `Circle approved. Waiting for Arc Testnet wallet (${attempt + 1}/12)…`,
        );
        await new Promise((resolve) => window.setTimeout(resolve, 1500));
    }
    throw new Error(
        'Circle has not returned an active Arc Testnet wallet yet. Reconnect the same login to check again. Do not create a different wallet.',
    );
}
