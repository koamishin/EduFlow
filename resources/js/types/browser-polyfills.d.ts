declare module 'vite-plugin-node-polyfills/shims/process' {
    const process: {
        browser: boolean;
        env: Record<string, string | undefined>;
        nextTick: (
            callback: (...args: unknown[]) => void,
            ...args: unknown[]
        ) => void;
        [key: string]: unknown;
    };
    export default process;
}
