import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { createPublicClient, custom, formatUnits, toHex } from 'viem';
import type { EIP1193Provider, Hex } from 'viem';
import { toast } from 'sonner';
import {
    CheckCircle2,
    Copy,
    ExternalLink,
    ShieldCheck,
    Wallet,
} from 'lucide-react';
import { arcTestnet } from 'viem/chains';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    executeCircleChallenge,
    waitForCircleWallet,
} from '@/lib/circle-wallet-onboarding';
import * as endpoints from '@/actions/App/Http/Controllers/StudentControlledWalletController';

type Quote = {
    id: string;
    snapshot: {
        sender: Hex;
        recipient: Hex;
        token: Hex;
        data: Hex;
        amount_base_units: number;
    };
};
type Props = {
    enabled: boolean;
    circleAppId: string | null;
    walletAuthMethod: 'pin' | 'google' | 'facebook' | null;
    socialProviders: { google: string | null; facebook: string | null };
    pastWallets?: {
        address: string;
        provider: string;
        replaced_at: string | null;
    }[];
    wallet: {
        address: string;
        provider: string;
        ownership_verified_at: string | null;
    } | null;
    accounts: { id: number; kind: string; label: string; remaining: string }[];
    payments: {
        id: string;
        tx_hash: string | null;
        via_circle?: boolean;
        posted: boolean;
        amount: string;
        applied: string;
        overpayment: string;
    }[];
};

async function post<T>(url: string, data: object = {}): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((v) => v.startsWith('XSRF-TOKEN='))
        ?.split('=')
        .slice(1)
        .join('=');
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(csrf ?? ''),
        },
        body: JSON.stringify(data),
    });
    const body = await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(body.errors ?? {})
                .flat()
                .join(' ') ||
                body.message ||
                'Request failed.',
        );
    return body as T;
}

function provider(): EIP1193Provider {
    const ethereum = (window as unknown as { ethereum?: EIP1193Provider })
        .ethereum;
    if (!ethereum)
        throw new Error(
            'No browser wallet detected. Choose Circle PIN wallet instead.',
        );
    return ethereum;
}

async function testnet() {
    const eth = provider();
    try {
        await eth.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: '0x4cef52' }],
        });
    } catch (error) {
        if ((error as { code?: number }).code !== 4902) throw error;
        await eth.request({
            method: 'wallet_addEthereumChain',
            params: [
                {
                    chainId: '0x4cef52',
                    chainName: 'Arc Testnet',
                    nativeCurrency: {
                        name: 'USDC',
                        symbol: 'USDC',
                        decimals: 18,
                    },
                    rpcUrls: ['https://rpc.testnet.arc.io'],
                    blockExplorerUrls: ['https://testnet.arcscan.app'],
                },
            ],
        });
    }
    if ((await eth.request({ method: 'eth_chainId' })) !== '0x4cef52')
        throw new Error('Switch to Arc Testnet 5042002 before continuing.');
    return eth;
}

export default function ControlledWallet({
    enabled,
    circleAppId,
    socialProviders,
    pastWallets = [],
    walletAuthMethod,
    wallet,
    accounts,
    payments,
}: Props) {
    const [connectedAddress, setConnectedAddress] = useState<string | null>(
        wallet?.ownership_verified_at ? wallet.address : null,
    );
    const connected = connectedAddress !== null;
    const circleLinked = wallet?.provider === 'circle-user';
    const [reconcileVia, setReconcileVia] = useState<
        'auto' | 'circle' | 'hash'
    >('auto');
    const [confirmingSwitch, setConfirmingSwitch] = useState(false);
    const canCircle = true;
    const canBrowser = true;
    const canPin =
        canCircle && (!walletAuthMethod || walletAuthMethod === 'pin');
    const success = (text: string) => {
        setMessage(text);
        toast.success(text);
    };
    const failure = (error: unknown) => {
        const text =
            error instanceof Error ? error.message : 'Wallet operation failed.';
        setMessage(text);
        toast.error(text);
    };
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [selection, setSelection] = useState('');
    const [amount, setAmount] = useState('');
    const [quote, setQuote] = useState<Quote | null>(null);
    const [hash, setHash] = useState('');
    const [recoveryId, setRecoveryId] = useState('');
    const sdk = useRef<import('@circle-fin/w3s-pw-web-sdk').W3SSdk | null>(
        null,
    );
    const run = async (action: () => Promise<void>) => {
        setBusy(true);
        setMessage('');
        try {
            await action();
        } catch (e) {
            failure(e);
        } finally {
            setBusy(false);
        }
    };
    const execute = (challenge: string) =>
        executeCircleChallenge(sdk.current!, challenge);
    const linkCircleWallet = () =>
        waitForCircleWallet(
            () => post(endpoints.circleConnect.url()),
            setMessage,
        );
    const circleConnect = () =>
        run(async () => {
            if (!circleAppId)
                throw new Error('Circle embedded wallets are not configured.');
            const { W3SSdk } = await import('@circle-fin/w3s-pw-web-sdk');
            sdk.current = new W3SSdk({ appSettings: { appId: circleAppId } });
            await sdk.current.getDeviceId();
            const auth = await post<{
                userToken: string;
                encryptionKey: string;
                challengeId: string | null;
            }>(endpoints.circleSession.url());
            sdk.current.setAuthentication({
                userToken: auth.userToken,
                encryptionKey: auth.encryptionKey,
            });
            setMessage(
                auth.challengeId
                    ? 'Approve wallet creation in the Circle window.'
                    : 'Checking your existing Circle wallet…',
            );
            if (auth.challengeId) await execute(auth.challengeId);
            setConnectedAddress(await linkCircleWallet());
            success('Circle wallet connected. Ready for testnet payments.');
            router.reload();
        });
    const initializeSocial = async (
        providerName: 'google' | 'facebook',
        resume = false,
    ) => {
        if (!circleAppId) throw new Error('Circle wallets are not configured.');
        const redirect = new URL(endpoints.show.url(), window.location.origin);
        redirect.searchParams.set('wallet_login', providerName);
        const { W3SSdk } = await import('@circle-fin/w3s-pw-web-sdk');
        let deviceId: string | undefined;
        if (!resume) {
            sdk.current ??= new W3SSdk({ appSettings: { appId: circleAppId } });
            deviceId = await sdk.current.getDeviceId();
        }
        const device = await post<{
            deviceToken: string;
            deviceEncryptionKey: string;
            nonce: string;
        }>(endpoints.socialDevice.url(), {
            provider: providerName,
            deviceId,
            resume,
        });
        const configs = {
            appSettings: { appId: circleAppId },
            loginConfigs: {
                deviceToken: device.deviceToken,
                deviceEncryptionKey: device.deviceEncryptionKey,
                ...(socialProviders.google
                    ? {
                          google: {
                              clientId: socialProviders.google,
                              redirectUri: new URL(redirect)
                                  .toString()
                                  .replace(
                                      /wallet_login=[^&]+/,
                                      'wallet_login=google',
                                  ),
                              selectAccountPrompt: true,
                          },
                      }
                    : {}),
                ...(socialProviders.facebook
                    ? {
                          facebook: {
                              appId: socialProviders.facebook,
                              redirectUri: new URL(redirect)
                                  .toString()
                                  .replace(
                                      /wallet_login=[^&]+/,
                                      'wallet_login=facebook',
                                  ),
                          },
                      }
                    : {}),
            },
        };
        const callbackTimer = resume
            ? window.setTimeout(() => {
                  setBusy(false);
                  setMessage(
                      'Circle did not return a social login callback. Check the registered redirect URI and browser cookie / popup restrictions, then reconnect the same login.',
                  );
              }, 60_000)
            : undefined;
        sdk.current = new W3SSdk(configs, async (error, result) => {
            window.clearTimeout(callbackTimer);
            if (error || !result) {
                setBusy(false);
                failure(
                    new Error(error?.message ?? 'Social wallet login failed.'),
                );
                return;
            }
            try {
                const response = await post<{ challengeId: string | null }>(
                    endpoints.socialComplete.url(),
                    {
                        userToken: result.userToken,
                        encryptionKey: result.encryptionKey,
                        provider: providerName,
                        nonce: device.nonce,
                    },
                );
                sdk.current!.setAuthentication({
                    userToken: result.userToken,
                    encryptionKey: result.encryptionKey,
                });
                window.history.replaceState(null, '', endpoints.show.url());
                if (response.challengeId)
                    await executeCircleChallenge(
                        sdk.current!,
                        response.challengeId,
                    );
                setConnectedAddress(await linkCircleWallet());
                success('Social wallet connected. Ready for testnet payments.');
                router.reload();
            } catch (e) {
                failure(e);
            } finally {
                setBusy(false);
            }
        });
        if (!resume) {
            const { SocialLoginProvider } =
                await import('@circle-fin/w3s-pw-web-sdk/dist/src/types');
            await sdk.current.performLogin(
                providerName === 'google'
                    ? SocialLoginProvider.GOOGLE
                    : SocialLoginProvider.FACEBOOK,
            );
        }
    };
    const resumed = useRef(false);
    useEffect(() => {
        const login = new URLSearchParams(window.location.search).get(
            'wallet_login',
        );
        if (
            !circleAppId ||
            resumed.current ||
            (login !== 'google' && login !== 'facebook')
        )
            return;
        resumed.current = true;
        setBusy(true);
        void initializeSocial(login, true).catch((error: unknown) => {
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'Could not resume social wallet login.',
            );
            setBusy(false);
        });
    });
    const connect = () =>
        run(async () => {
            const eth = await testnet();
            const [address] = await eth.request({
                method: 'eth_requestAccounts',
            });
            const challenge = await post<{ message: string }>(
                endpoints.challenge.url(),
                { address },
            );
            const signature = await eth.request({
                method: 'personal_sign',
                params: [toHex(challenge.message), address],
            });
            const linked = await post<{ address: string }>(
                endpoints.connect.url(),
                { signature },
            );
            setConnectedAddress(linked.address);
            success('Browser wallet verified and connected.');
            router.reload();
        });
    const prepare = () =>
        run(async () => {
            const account = accounts.find(
                (a) => `${a.kind}:${a.id}` === selection,
            );
            if (!account) throw new Error('Choose a bill or tuition account.');
            const q = await post<Quote>(endpoints.quote.url(), {
                kind: account.kind,
                account_id: account.id,
                amount,
            });
            setQuote(q);
            setRecoveryId(q.id);
            setHash('');
        });
    const pay = () =>
        run(async () => {
            if (!quote) return;
            if (wallet?.provider === 'circle-user') {
                if (!sdk.current)
                    throw new Error(
                        'Reconnect Circle session before approving payment.',
                    );
                const result = await post<{ challengeId: string }>(
                    endpoints.circlePay.url(quote.id),
                    { consent: true },
                );
                await execute(result.challengeId);
                setQuote(null);
                const resultStatus = await post<{
                    posted: boolean;
                    tx_hash?: string;
                }>(endpoints.circleStatus.url(quote.id));
                if (resultStatus.tx_hash) setHash(resultStatus.tx_hash);
                setMessage(
                    resultStatus.posted
                        ? 'Settlement verified. Testnet allocation recorded.'
                        : 'Circle authorization complete. Use Check Circle settlement below; do not submit another payment.',
                );
                return;
            }
            const eth = await testnet();
            const [sender] = await eth.request({
                method: 'eth_requestAccounts',
            });
            if (sender.toLowerCase() !== quote.snapshot.sender)
                throw new Error(
                    'Selected wallet differs from verified student wallet.',
                );
            const client = createPublicClient({
                chain: arcTestnet,
                transport: custom(eth),
            });
            const tx = {
                account: sender,
                to: quote.snapshot.token,
                data: quote.snapshot.data,
                value: 0n,
            };
            const gas = await client.estimateGas(tx);
            const gasPrice = await client.getGasPrice();
            const maxFeePerGas = gasPrice * 2n;
            if (
                !window.confirm(
                    `Arc Testnet only. Send ${formatUnits(BigInt(quote.snapshot.amount_base_units), 6)} USDC to ${quote.snapshot.recipient}. Maximum network fee: ${formatUnits(gas * maxFeePerGas, 18)} USDC. Test funds do not pay official charges. Approve?`,
                )
            )
                return;
            setQuote(null);
            await post(endpoints.start.url(quote.id), { consent: true });
            const txHash = await eth.request({
                method: 'eth_sendTransaction',
                params: [
                    {
                        from: sender,
                        to: quote.snapshot.token,
                        data: quote.snapshot.data,
                        value: '0x0',
                        gas: toHex(gas),
                        maxFeePerGas: toHex(maxFeePerGas),
                        maxPriorityFeePerGas: '0x0',
                    },
                ],
            });
            setHash(txHash);
            localStorage.setItem(`student-payment-${quote.id}`, txHash);
            setQuote(null);
            setMessage(
                'Transaction submitted. Verify settlement below; do not pay again while outcome is unknown.',
            );
        });
    const circleStatus = () =>
        run(async () => {
            const result = await post<{
                posted: boolean;
                tx_hash?: string;
                state?: string;
            }>(endpoints.circleStatus.url(recoveryId));
            if (result.tx_hash) setHash(result.tx_hash);
            setMessage(
                result.posted
                    ? 'Settlement verified. Testnet allocation recorded.'
                    : `Circle state: ${result.state ?? 'pending'}. Do not pay again.`,
            );
            if (result.posted) router.reload();
        });
    const verify = () =>
        run(async () => {
            await post(endpoints.confirm.url(recoveryId), { tx_hash: hash });
            localStorage.removeItem(`student-payment-${recoveryId}`);
            success('Settlement verified. Testnet bill allocation recorded.');
            router.reload();
        });
    return (
        <>
            <Head title="Student-controlled wallet" />
            <main className="mx-auto flex max-w-3xl flex-col gap-6 p-6">
                <header>
                    <p className="mb-2 text-xs font-medium uppercase tracking-widest text-muted-foreground">
                        Student finance / wallet
                    </p>
                    <h1 className="text-3xl font-semibold tracking-tight">
                        Your wallet, your approval
                    </h1>
                    <p>
                        Arc Testnet · 5042002 · Test funds only. No official
                        tuition revenue.
                    </p>
                </header>
                {connected && !confirmingSwitch && (
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => setConfirmingSwitch(true)}
                    >
                        Change active wallet
                    </Button>
                )}
                {connected && confirmingSwitch && (
                    <section
                        role="alertdialog"
                        aria-label="Change active wallet"
                        className="rounded-2xl border border-amber-500/40 bg-amber-500/5 p-5 sm:p-7"
                    >
                        <h2 className="text-lg font-semibold">
                            Switch to another wallet?
                        </h2>
                        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            <li>
                                Your current balance stays at{' '}
                                <span className="font-mono">
                                    {connectedAddress.slice(0, 10)}…
                                </span>
                                . Nothing moves automatically.
                            </li>
                            <li>
                                Future payments and payouts use the new address.
                                Old receipts stay linked to the old wallet.
                            </li>
                            <li>
                                Funding the new address with the Circle faucet
                                requires Arc Testnet and the new address.
                            </li>
                            <li>
                                Move funds yourself by approving a transfer from
                                your old wallet, keeping USDC for gas.
                            </li>
                        </ul>
                        <div className="mt-5 flex flex-wrap gap-3">
                            <Button onClick={() => setConfirmingSwitch(false)}>
                                Yes, choose another wallet
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => setConfirmingSwitch(false)}
                            >
                                Keep my current wallet
                            </Button>
                        </div>
                    </section>
                )}
                <p role="status" aria-live="polite">
                    {message ||
                        (busy ? 'Waiting for Circle or your wallet…' : '')}
                </p>
                {!enabled && (
                    <p role="alert">
                        Student-controlled testnet payments are disabled by this
                        installation.
                    </p>
                )}
                <section className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                    {connected ? (
                        <div className="flex flex-col gap-5 p-5 sm:p-7">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <span className="rounded-full bg-primary/10 p-3 text-primary">
                                        <ShieldCheck className="size-6" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
                                            {circleLinked
                                                ? 'Circle student wallet'
                                                : 'Browser student wallet'}
                                        </p>
                                        <h2 className="text-xl font-semibold">
                                            Connected & verified
                                        </h2>
                                    </div>
                                </div>
                                <span className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium">
                                    <CheckCircle2 className="size-3.5" />
                                    Arc Testnet
                                </span>
                            </div>
                            <div className="rounded-xl border bg-muted/40 p-4">
                                <p className="mb-2 text-xs text-muted-foreground">
                                    Your receiving & payment address
                                </p>
                                <p className="break-all font-mono text-sm leading-relaxed">
                                    {connectedAddress}
                                </p>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            run(async () => {
                                                await navigator.clipboard.writeText(
                                                    connectedAddress!,
                                                );
                                                success(
                                                    'Wallet address copied.',
                                                );
                                            })
                                        }
                                    >
                                        <Copy className="size-3.5" />
                                        Copy address
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={`https://testnet.arcscan.app/address/${connectedAddress}`}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <ExternalLink className="size-3.5" />
                                            View on explorer
                                        </a>
                                    </Button>
                                </div>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Your wallet is linked. Fund it with testnet
                                USDC, then choose a bill below. Each transfer
                                still needs your approval.
                            </p>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-3 p-5 sm:p-7">
                            <Wallet className="size-6 text-primary" />
                            <h2 className="text-xl font-semibold">
                                Connect your student wallet
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Choose a browser wallet or extension-free Circle
                                wallet. You control every payment.
                            </p>
                        </div>
                    )}
                    {pastWallets.length > 0 && (
                        <div className="border-t p-5 sm:px-7">
                            <h3 className="font-semibold">Previous wallets</h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Replaced wallets keep their receipts and funding
                                history. Their funds never moved with the
                                account.
                            </p>
                            <ul className="mt-3 space-y-2">
                                {pastWallets.map((wallet) => (
                                    <li
                                        key={wallet.address}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-background px-3 py-2 text-xs"
                                    >
                                        <span className="font-mono break-all">
                                            {wallet.address}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {wallet.provider === 'circle-user'
                                                ? 'Circle wallet'
                                                : 'Browser wallet'}{' '}
                                            · {wallet.replaced_at ?? 'replaced'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div className="border-t bg-muted/30 p-5 sm:px-7">
                        <h3 className="font-semibold">
                            Your balance stays with your wallet
                        </h3>
                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                            Connecting or reconnecting never transfers USDC.
                            Funds remain on the original address. This app
                            protects your linked payment wallet: another address
                            or wallet type cannot overwrite it. Moving funds
                            requires a separate transfer you approve in the
                            original wallet.
                        </p>
                    </div>
                    <div className="flex flex-col gap-3 border-t p-5 sm:px-7">
                        <h3 className="text-sm font-semibold">
                            {connected
                                ? 'Reconnect your wallet session'
                                : 'Choose how to connect'}
                        </h3>

                        <p>
                            Use MetaMask, or create an embedded Circle PIN
                            wallet without an extension. Circle recovery depends
                            on your PIN and recovery answers; school cannot
                            recover them.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <Button
                                disabled={!enabled || busy || !canBrowser}
                                onClick={connect}
                            >
                                Connect browser wallet
                            </Button>
                            <Button
                                disabled={
                                    !enabled || !circleAppId || busy || !canPin
                                }
                                onClick={circleConnect}
                            >
                                Create / reconnect PIN wallet
                            </Button>
                            {(['google', 'facebook'] as const).map((name) => (
                                <Button
                                    key={name}
                                    variant="outline"
                                    disabled={
                                        !enabled ||
                                        busy ||
                                        !circleAppId ||
                                        !socialProviders[name] ||
                                        (walletAuthMethod !== null &&
                                            walletAuthMethod !== name)
                                    }
                                    onClick={() =>
                                        run(() => initializeSocial(name))
                                    }
                                >
                                    Connect with{' '}
                                    {name === 'google' ? 'Google' : 'Facebook'}
                                </Button>
                            ))}
                        </div>
                        <p>
                            Social login unlocks your Circle wallet, not your
                            school account. Use the same login method each time;
                            existing wallets are never replaced automatically.
                        </p>
                        <a
                            href="https://faucet.circle.com"
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            Get testnet USDC from Circle faucet
                        </a>
                        <p>
                            Select Arc Testnet and paste your linked wallet
                            address. Keep USDC for gas.
                        </p>
                    </div>
                </section>
                <section className="flex flex-col gap-4 rounded-2xl border bg-card p-5 sm:p-7">
                    <div>
                        <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
                            01 / Payment
                        </p>
                        <h2 className="mt-2 text-xl font-semibold">
                            Pay a testnet bill
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Choose an account, review the exact amount, then
                            approve in your linked wallet.
                        </p>
                    </div>
                    <Label htmlFor="account">Tuition or bill</Label>
                    <select
                        id="account"
                        className="rounded border bg-background p-2"
                        value={selection}
                        onChange={(e) => {
                            setSelection(e.target.value);
                            setQuote(null);
                        }}
                    >
                        <option value="">Choose account</option>
                        {accounts.map((a) => (
                            <option
                                key={`${a.kind}:${a.id}`}
                                value={`${a.kind}:${a.id}`}
                            >
                                {a.label} —{' '}
                                {formatUnits(BigInt(a.remaining), 6)} USDC
                            </option>
                        ))}
                    </select>
                    <Label htmlFor="amount">Amount (USDC)</Label>
                    <Input
                        id="amount"
                        value={amount}
                        onChange={(e) => {
                            setAmount(e.target.value);
                            setQuote(null);
                        }}
                        inputMode="decimal"
                    />
                    <Button
                        disabled={!enabled || busy || !connected}
                        onClick={prepare}
                    >
                        Review payment
                    </Button>
                    {quote && (
                        <div className="flex flex-col gap-3">
                            <p className="break-all">
                                Send{' '}
                                {formatUnits(
                                    BigInt(quote.snapshot.amount_base_units),
                                    6,
                                )}{' '}
                                USDC to {quote.snapshot.recipient} on Arc
                                Testnet. Network fees are additional; review
                                them in your wallet before approval.
                            </p>
                            <Button disabled={busy} onClick={pay}>
                                Approve in my wallet
                            </Button>
                        </div>
                    )}
                </section>
                <section className="flex flex-col gap-4 rounded-2xl border bg-card p-5 sm:p-7">
                    <div>
                        <p className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
                            02 / Recovery & receipts
                        </p>
                        <h2 className="mt-2 text-xl font-semibold">
                            Verify existing transfer
                        </h2>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            disabled={busy || !recoveryId}
                            variant={
                                reconcileVia === 'hash' ? 'outline' : 'default'
                            }
                            onClick={() => {
                                setReconcileVia('hash');
                                void verify();
                            }}
                        >
                            Verify with transaction hash
                        </Button>
                        <Button
                            disabled={busy || !recoveryId}
                            variant={
                                reconcileVia === 'circle'
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => {
                                setReconcileVia('circle');
                                void circleStatus();
                            }}
                        >
                            Check Circle settlement
                        </Button>
                    </div>
                    <p>
                        Keep payment reference and hash after sending. An
                        unresolved transfer must not be resent.
                    </p>
                    <Label htmlFor="reference">Payment reference</Label>
                    <Input
                        id="reference"
                        value={recoveryId}
                        onChange={(e) => setRecoveryId(e.target.value)}
                    />
                    <Label htmlFor="hash">Transaction hash</Label>
                    <Input
                        id="hash"
                        value={hash}
                        onChange={(e) => setHash(e.target.value)}
                    />
                    <Button
                        disabled={!enabled || busy || !hash || !recoveryId}
                        onClick={verify}
                    >
                        Verify on-chain settlement
                    </Button>
                    {payments.map((p) => (
                        <div key={p.id} className="border-t py-2">
                            <p>
                                {formatUnits(BigInt(p.amount), 6)} USDC ·{' '}
                                {p.posted
                                    ? 'Verified testnet allocation'
                                    : 'Unresolved — do not pay again'}
                            </p>
                            <p className="break-all">{p.id}</p>
                            {!p.posted && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setRecoveryId(p.id);
                                        setReconcileVia(
                                            p.via_circle ? 'circle' : 'hash',
                                        );
                                        setHash(
                                            p.tx_hash ??
                                                localStorage.getItem(
                                                    `student-payment-${p.id}`,
                                                ) ??
                                                '',
                                        );
                                    }}
                                >
                                    {p.via_circle
                                        ? 'Check Circle settlement'
                                        : 'Verify with hash'}
                                </Button>
                            )}
                        </div>
                    ))}
                </section>
                <p role="status" className="break-words">
                    {busy ? 'Waiting for wallet or server…' : message}
                </p>
            </main>
        </>
    );
}
