import { Form, Head, Link } from '@inertiajs/react';
import { AlertCircle, Building2 } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import SocialLoginButtons from '@/components/social-login-buttons';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
/* @chisel-registration */
import { register } from '@/routes';
/* @end-chisel-registration */
import { store } from '@/routes/login';
import { request } from '@/routes/password';
/* @chisel-passkeys */
import PasskeyVerify from '@/components/passkey-verify';
/* @end-chisel-passkeys */

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    hasInstitutions?: boolean;
};

export default function Login({
    status,
    canResetPassword,
    hasInstitutions = true,
}: Props) {
    return (
        <>
            <Head title="Log in" />

            {/* @chisel-passkeys */}
            <PasskeyVerify />
            {/* @end-chisel-passkeys */}

            {!hasInstitutions && (
                <div className="mb-6 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 space-y-2.5 text-center">
                    <div className="mx-auto flex size-9 items-center justify-center rounded-lg bg-amber-500/20 text-amber-400">
                        <Building2 className="size-4.5" />
                    </div>
                    <div className="space-y-1">
                        <p className="text-xs font-semibold text-amber-500 uppercase tracking-wider">
                            Student Login Disabled
                        </p>
                        <p className="text-xs text-muted-foreground leading-relaxed">
                            No educational institutions are registered on this
                            system yet. Students cannot sign in until a school
                            administrator completes institution setup.
                        </p>
                    </div>
                    <div className="pt-1">
                        <Link href="/institutions/register">
                            <Button
                                size="sm"
                                className="w-full bg-[#c4a46c] text-stone-950 hover:bg-[#d6b77e] font-semibold text-xs h-8"
                            >
                                Register Educational Institution
                            </Button>
                        </Link>
                    </div>
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    disabled={!hasInstitutions || processing}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && hasInstitutions && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Forgot your password?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                    disabled={!hasInstitutions || processing}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                    disabled={!hasInstitutions || processing}
                                />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={!hasInstitutions || processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {hasInstitutions
                                    ? 'Log in'
                                    : 'Student Login Disabled'}
                            </Button>
                        </div>

                        {/* @chisel-registration */}
                        <div className="text-center text-sm text-muted-foreground">
                            Don't have an account?{' '}
                            <TextLink href={register()} tabIndex={5}>
                                Sign up
                            </TextLink>
                        </div>
                        {/* @end-chisel-registration */}
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <SocialLoginButtons />
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
};
