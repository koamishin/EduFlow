import { Head } from '@inertiajs/react';

type Post = {
    id: number;
    title: string;
    slug: string;
    created_at: string | null;
};

export default function PostIndex({ posts }: { posts: Post[] }) {
    return (
        <>
            <Head title="Posts" />
            <div className="mx-auto max-w-3xl px-4 py-8">
                <h1 className="mb-6 text-2xl font-semibold">Posts</h1>
                {posts.length ? (
                    <ul className="divide-y divide-border rounded-md border border-border bg-card">
                        {posts.map((post) => (
                            <li
                                key={post.id}
                                className="flex items-center justify-between px-4 py-3"
                            >
                                <div>
                                    <p className="font-medium text-foreground">
                                        {post.title}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        /{post.slug}
                                    </p>
                                </div>
                                {post.created_at && (
                                    <time
                                        dateTime={post.created_at}
                                        className="text-xs text-muted-foreground/80"
                                    >
                                        {new Date(
                                            post.created_at,
                                        ).toLocaleDateString()}
                                    </time>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No posts yet.
                    </p>
                )}
            </div>
        </>
    );
}
