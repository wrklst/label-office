import { router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import NoticeController from '@/actions/App/Http/Controllers/NoticeController';
import { Button } from '@/components/ui/button';

/**
 * A standing notice. With an `id` it can be acknowledged, which hides it
 * for this person everywhere until they bring the notices back under
 * Settings → Terms of use. Without one it always shows.
 */
export function Notice({ id, children }: { id?: string; children: string }) {
    const { auth } = usePage().props;

    if (id && (auth.user?.hidden_notices ?? []).includes(id)) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-start gap-x-4 gap-y-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            <p className="flex min-w-0 flex-1 basis-80 gap-2">
                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
                <span>{children}</span>
            </p>
            {id && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        router.post(
                            NoticeController.hide.url(id),
                            {},
                            { preserveScroll: true, preserveState: true },
                        )
                    }
                >
                    Understood, hide
                </Button>
            )}
        </div>
    );
}
