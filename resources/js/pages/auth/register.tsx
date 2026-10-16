import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    Building2,
    CheckCircle2,
    GraduationCap,
    ShieldCheck,
    Wallet as WalletIcon,
} from 'lucide-react';
import React, { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

interface EthereumProvider {
    request: (args: { method: string; params?: unknown[] }) => Promise<unknown>;
}

interface Institution {
    id: number;
    name: string;
    slug: string;
    domain?: string;
}

type Props = {
    passwordRules: string;
    institutions?: Institution[];
    hasInstitutions?: boolean;
    currentInstitution?: Institution | null;
};

export default function Register({
    passwordRules,
    institutions = [],
    hasInstitutions,
    currentInstitution = null,
}: Props) {
    const isInstitutionAvailable = hasInstitutions ?? (institutions.length > 0 || currentInstitution !== null);
    const [selectedInstitutionId, setSelectedInstitutionId] = useState<string>(
        currentInstitution?.id ? String(currentInstitution.id) : (institutions[0]?.id ? String(institutions[0].id) : '')
    );
    const [walletAddress, setWalletAddress] = useState<string>('');
    const [isWalletVerified, setIsWalletVerified] = useState<boolean>(false);
    const [connectingWallet, setConnectingWallet] = useState<boolean>(false);
    const [walletError, setWalletError] = useState<string | null>(null);

    const handleConnectWallet = async () => {
        setWalletError(null);
        const ethereum =
            typeof window !== 'undefined'
                ? (window as unknown as { ethereum?: EthereumProvider }).ethereum
                : undefined;

        if (!ethereum) {
            setWalletError('No EIP-1193 wallet (MetaMask, Phantom, Rainbow) detected. Please enter your Arc address manually below.');
            return;
        }

        try {
            setConnectingWallet(true);
            const accounts = (await ethereum.request({
                method: 'eth_requestAccounts',
            })) as string[];

            if (!accounts || accounts.length === 0) {
                throw new Error('No account returned by wallet.');
            }

            const address = accounts[0];
            setWalletAddress(address);

            // Request personal_sign verification for cryptographic ownership proof
            const message = `Sign to verify your student wallet address for EduFlow:\n${address}\nTimestamp: ${new Date().toISOString()}`;
            const hexMessage =
                '0x' +
                Array.from(new TextEncoder().encode(message))
                    .map((b) => b.toString(16).padStart(2, '0'))
                    .join('');

            try {
                await ethereum.request({
                    method: 'personal_sign',
                    params: [hexMessage, address],
                });
                setIsWalletVerified(true);
            } catch (signErr) {
                console.warn('Personal sign skipped or rejected', signErr);
                // Even if signature is cancelled, the connected address is captured
            }
        } catch (err: unknown) {
            const errorMessage = err instanceof Error ? err.message : 'Failed to connect wallet.';
            setWalletError(errorMessage);
        } finally {
            setConnectingWallet(false);
        }
    };

    if (!isInstitutionAvailable) {
        return (
            <>
                <Head title="Student Registration Unavailable" />
                <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-6 sm:p-8 text-center space-y-5">
                    <div className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-500 shadow-sm">
                        <Building2 className="size-7" />
                    </div>
                    <div className="space-y-2">
                        <h2 className="font-serif text-xl sm:text-2xl font-bold tracking-tight text-foreground">
                            Student Registration Unavailable
                        </h2>
                        <p className="text-xs sm:text-sm text-muted-foreground max-w-md mx-auto leading-relaxed">
                            No educational institutions have been registered or configured on this OpenSinop deployment yet. Students cannot enroll or register Web3 payout wallets until an institution administrator sets up the school treasury.
                        </p>
                    </div>

                    <div className="pt-2 flex flex-col sm:flex-row gap-3 justify-center">
                        <Link href="/institutions/register" className="w-full sm:w-auto">
                            <Button className="w-full sm:w-auto bg-[#c4a46c] text-stone-950 hover:bg-[#d6b77e] font-semibold text-xs px-6 py-2.5">
                                Register Educational Institution
                            </Button>
                        </Link>
                    </div>

                    <div className="pt-4 border-t border-border/60 text-xs text-muted-foreground">
                        Already an administrator?{' '}
                        <TextLink href={login()} className="font-semibold text-foreground hover:underline">
                            Log In to Console
                        </TextLink>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Register Student Account" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            {/* Institution Scoping Section */}
                            {currentInstitution ? (
                                <div className="rounded-xl border border-[#c4a46c]/40 bg-[#1c1915]/60 p-3.5 space-y-1.5">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold text-[#c4a46c] flex items-center gap-1.5 uppercase tracking-wider">
                                            <Building2 className="size-3.5" />
                                            Enrolling School
                                        </span>
                                        <Badge variant="outline" className="text-[10px] border-[#c4a46c]/40 text-[#c4a46c]">
                                            Locked
                                        </Badge>
                                    </div>
                                    <p className="text-sm font-medium text-white">{currentInstitution.name}</p>
                                    <input
                                        type="hidden"
                                        name="institution_id"
                                        value={currentInstitution.id}
                                    />
                                </div>
                            ) : institutions.length > 0 ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="institution_id">Select Institution</Label>
                                    <select
                                        id="institution_id"
                                        name="institution_id"
                                        value={selectedInstitutionId}
                                        onChange={(e) => setSelectedInstitutionId(e.target.value)}
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring"
                                    >
                                        {institutions.map((inst) => (
                                            <option key={inst.id} value={inst.id} className="bg-background text-foreground">
                                                {inst.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.institution_id} />
                                </div>
                            ) : null}

                            {/* Basic User Details */}
                            <div className="grid gap-2">
                                <Label htmlFor="name">Full Name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Jane Doe"
                                />
                                <InputError message={errors.name} className="mt-1" />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="jane.doe@school.edu"
                                />
                                <InputError message={errors.email} />
                            </div>

                            {/* Student Academic Identity */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="student_id_number" className="flex items-center gap-1.5">
                                        <GraduationCap className="size-3.5 text-muted-foreground" />
                                        Student ID Number
                                    </Label>
                                    <Input
                                        id="student_id_number"
                                        type="text"
                                        required
                                        tabIndex={3}
                                        name="student_id_number"
                                        placeholder="STU-2026-0891"
                                    />
                                    <InputError message={errors.student_id_number} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="department">Department</Label>
                                    <Input
                                        id="department"
                                        type="text"
                                        required
                                        tabIndex={4}
                                        name="department"
                                        placeholder="e.g. Computer Science"
                                    />
                                    <InputError message={errors.department} />
                                </div>
                            </div>

                            {/* Web3 Student Wallet Section */}
                            <div className="rounded-xl border border-border bg-card/50 p-4 space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label className="text-xs font-semibold flex items-center gap-1.5 uppercase tracking-wider text-foreground">
                                        <WalletIcon className="size-3.5 text-[#c4a46c]" />
                                        Arc Web3 Payout Wallet
                                    </Label>
                                    {isWalletVerified ? (
                                        <Badge className="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[10px] flex items-center gap-1">
                                            <CheckCircle2 className="size-3" />
                                            Signature Verified
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="text-[10px] text-muted-foreground">
                                            EIP-1193 / Arc
                                        </Badge>
                                    )}
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    Used to receive grants, stipends, and verify tuition payment claims on Arc.
                                </p>

                                {/* 1-Click Connect Wallet Button */}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={handleConnectWallet}
                                    disabled={connectingWallet}
                                    className="w-full flex items-center justify-center gap-2 border-[#c4a46c]/40 hover:bg-[#c4a46c]/10 text-xs font-medium"
                                >
                                    {connectingWallet ? (
                                        <>
                                            <Spinner className="size-3.5" />
                                            Connecting & Requesting Signature...
                                        </>
                                    ) : (
                                        <>
                                            <ShieldCheck className="size-4 text-[#c4a46c]" />
                                            1-Click Connect Browser Wallet (MetaMask / Rainbow)
                                        </>
                                    )}
                                </Button>

                                {walletError && (
                                    <p className="text-xs text-amber-500/90">{walletError}</p>
                                )}

                                {/* Manual Wallet Fallback Input */}
                                <div className="space-y-1.5 pt-1">
                                    <Label htmlFor="wallet_address" className="text-[11px] text-muted-foreground">
                                        Or enter Arc address manually (0x... 40 hex characters)
                                    </Label>
                                    <Input
                                        id="wallet_address"
                                        type="text"
                                        required
                                        tabIndex={5}
                                        name="wallet_address"
                                        value={walletAddress}
                                        onChange={(e) => {
                                            setWalletAddress(e.target.value);
                                            setIsWalletVerified(false);
                                        }}
                                        placeholder="0x71C...49A"
                                        className="font-mono text-xs"
                                    />
                                    <InputError message={errors.wallet_address} />
                                </div>
                            </div>

                            {/* Passwords */}
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={6}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={7}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={8}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Complete Student Registration
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()} tabIndex={9}>
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Create an account',
    description: 'Enter your student credentials and Arc payout wallet to enroll',
};
