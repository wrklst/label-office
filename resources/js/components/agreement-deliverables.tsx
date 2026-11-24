import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { dateTime } from '@/lib/format';

export type Deliverable = {
    id: number;
    title: string;
    status: string;
    file: string | null;
    file_id: number | null;
    returned_note: string | null;
    delivered_at: string | null;
    accepted_at: string | null;
};

const STATUS: Record<string, string> = {
    open: 'Not delivered',
    delivered: 'Delivered',
    returned: 'Handed back',
    accepted: 'Accepted',
};

/**
 * What an agreement asks to be delivered, each with where it stands.
 * What can be done with an item differs for the label and the payee.
 */
export function DeliverableList({
    deliverables,
    file,
    children,
}: {
    deliverables: Deliverable[];
    /** How the handed-in file is shown: a link for the label, its name for the payee. */
    file?: (deliverable: Deliverable) => ReactNode;
    children: (deliverable: Deliverable) => ReactNode;
}) {
    return (
        <ul className="divide-y rounded-lg border text-sm">
            {deliverables.map((deliverable) => (
                <li key={deliverable.id} className="grid gap-2 px-3 py-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <span className="font-medium">{deliverable.title}</span>
                        <Badge
                            variant={
                                deliverable.status === 'accepted'
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            {STATUS[deliverable.status] ?? deliverable.status}
                        </Badge>
                    </div>
                    {deliverable.file && (
                        <p className="text-muted-foreground">
                            {file ? file(deliverable) : deliverable.file}
                            {deliverable.delivered_at &&
                                ` · delivered ${dateTime(deliverable.delivered_at)}`}
                        </p>
                    )}
                    {deliverable.returned_note && (
                        <p className="text-amber-700 dark:text-amber-500">
                            Handed back: {deliverable.returned_note}
                        </p>
                    )}
                    {children(deliverable)}
                </li>
            ))}
        </ul>
    );
}
