import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

type FilterValue = string | number | boolean | null | undefined;

/**
 * The choice lists of the page (props named …Options). Filtering, sorting
 * and paging do not change them, so those visits leave them out.
 */
export function useChoiceLists(): string[] {
    return Object.keys(usePage().props).filter((name) =>
        name.endsWith('Options'),
    );
}

/**
 * Reload the current list with changed query filters. Text input is
 * debounced; empty values are dropped from the URL.
 */
export function useFilters<T extends Record<string, FilterValue>>(
    url: string,
    current: T,
) {
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const except = useChoiceLists();

    // A reload still waiting when the page is left would pull the user back to the list.
    useEffect(
        () => () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    const apply = (changes: Partial<T>, debounce = false) => {
        const query: Record<string, string | number> = {};

        Object.entries({ ...current, ...changes }).forEach(([key, value]) => {
            if (value === true) {
                query[key] = 1;
            } else if (
                value !== null &&
                value !== undefined &&
                value !== '' &&
                value !== false
            ) {
                query[key] = value;
            }
        });

        const visit = () =>
            router.get(url, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                except,
            });

        if (timer.current) {
            clearTimeout(timer.current);
        }

        if (debounce) {
            timer.current = setTimeout(visit, 300);
        } else {
            visit();
        }
    };

    return { apply };
}
