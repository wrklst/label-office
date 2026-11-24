import { Check, ChevronDown, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import OptionsController from '@/actions/App/Http/Controllers/OptionsController';
import { cn } from '@/lib/utils';

export type RecordKind =
    | 'releases'
    | 'tracks'
    | 'artists'
    | 'payees'
    | 'contracts'
    | 'ledgers'
    | 'territories'
    | 'periods'
    | 'agreement-templates';

export type RecordOption = {
    id: number;
    name: string;
    hint: string | null;
    image: string | null;
};

type Page = {
    data: RecordOption[];
    next: number | null;
    selected: RecordOption | null;
};

/** The kinds whose rows carry a picture, so a row without one keeps its place. */
const PICTURED: RecordKind[] = ['releases', 'tracks', 'artists'];

function Picture({ option }: { option: RecordOption }) {
    return option.image ? (
        <img
            src={option.image}
            alt=""
            loading="lazy"
            className="size-8 shrink-0 rounded object-cover"
        />
    ) : (
        <span className="size-8 shrink-0 rounded bg-muted" />
    );
}

/**
 * A dropdown of records of the label. It searches on the server and
 * loads thirty rows at a time as the list is scrolled, so it stays
 * quick however many records there are. It sends its value under
 * `name` like a select does, and can be controlled with `value` and
 * `onChange`.
 */
export function RecordSelect({
    records,
    name,
    id,
    value,
    defaultValue,
    onChange,
    placeholder = 'None',
    required,
    disabled,
    params,
    className,
}: {
    records: RecordKind;
    name?: string;
    id?: string;
    value?: number | string | null;
    defaultValue?: number | string | null;
    onChange?: (id: number | '', option: RecordOption | null) => void;
    /** What the dropdown reads while nothing is chosen. It is also the row that clears the choice. */
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    /** Narrows the list, such as `{ release_id }` for the tracks of one release. */
    params?: Record<string, string | number | null | undefined>;
    className?: string;
}) {
    const controlled = value !== undefined;
    const [inner, setInner] = useState<number | ''>(
        defaultValue ? Number(defaultValue) : '',
    );
    const current: number | '' = controlled
        ? value
            ? Number(value)
            : ''
        : inner;

    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<RecordOption[]>([]);
    const [next, setNext] = useState<number | null>(1);
    const [loading, setLoading] = useState(false);
    const [chosen, setChosen] = useState<RecordOption | null>(null);

    const root = useRef<HTMLDivElement>(null);
    const end = useRef<HTMLLIElement>(null);
    const list = useRef<HTMLUListElement>(null);
    const [upward, setUpward] = useState(false);
    const request = useRef(0);
    const filter = JSON.stringify(params ?? {});
    const pictured = PICTURED.includes(records);

    const load = useCallback(
        async (page: number, term: string, selected?: number) => {
            const ticket = ++request.current;

            setLoading(true);

            const response = await fetch(
                OptionsController.url(records, {
                    query: {
                        ...(JSON.parse(filter) as Record<string, string>),
                        search: term || undefined,
                        page,
                        selected,
                    },
                }),
                { headers: { Accept: 'application/json' } },
            ).catch(() => null);

            // A later request has taken over: its answer is the one to show.
            if (ticket !== request.current) {
                return;
            }

            setLoading(false);

            if (!response?.ok) {
                setNext(null);

                return;
            }

            const result: Page = await response.json();

            setOptions((before) =>
                page === 1 ? result.data : [...before, ...result.data],
            );
            setNext(result.next);

            if (selected) {
                setChosen(result.selected);
            }
        },
        [records, filter],
    );

    // What is chosen is shown by its name, which only the server knows at first.
    useEffect(() => {
        if (current === '') {
            setChosen(null);
        } else if (chosen?.id !== current) {
            void load(1, '', current);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [current]);

    // Opening, typing and a change of what narrows the list each start it from the top.
    useEffect(() => {
        if (!open) {
            return;
        }

        const wait = setTimeout(() => void load(1, search), search ? 200 : 0);

        return () => clearTimeout(wait);
    }, [open, search, load]);

    // Reaching the end of the list fetches the next thirty.
    useEffect(() => {
        if (!open || !end.current || next === null || next === 1 || loading) {
            return;
        }

        // Measured against the list itself: it may reach below the window, where nothing counts as seen.
        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    void load(next, search);
                }
            },
            { root: list.current },
        );

        observer.observe(end.current);

        return () => observer.disconnect();
    }, [open, next, loading, search, load]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const choose = (option: RecordOption | null) => {
        setChosen(option);
        setInner(option?.id ?? '');
        onChange?.(option?.id ?? '', option);
        setOpen(false);
        setSearch('');
    };

    return (
        <div ref={root} className={cn('relative min-w-0', className)}>
            {name && (
                // A hidden field cannot be required; this one is unseen but still stops the form when empty.
                <input
                    name={name}
                    value={current}
                    onChange={() => undefined}
                    required={required}
                    tabIndex={-1}
                    aria-hidden
                    className="pointer-events-none absolute bottom-0 left-4 size-px opacity-0"
                />
            )}
            <button
                type="button"
                id={id}
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                onClick={(event) => {
                    // Without room below, the list opens above the field.
                    const { top, bottom } =
                        event.currentTarget.getBoundingClientRect();

                    setUpward(
                        window.innerHeight - bottom < 340 &&
                            top > window.innerHeight - bottom,
                    );
                    setOpen(!open);
                }}
                className="flex h-9 w-full min-w-0 items-center gap-2 rounded-md border border-input bg-transparent px-2.5 text-left text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
            >
                {chosen?.image && (
                    <img
                        src={chosen.image}
                        alt=""
                        className="size-5 shrink-0 rounded object-cover"
                    />
                )}
                <span
                    className={cn(
                        'min-w-0 flex-1 truncate',
                        current === '' && 'text-muted-foreground',
                    )}
                >
                    {current === '' ? placeholder : (chosen?.name ?? '…')}
                </span>
                <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
            </button>

            {open && (
                <div
                    className={cn(
                        'absolute z-50 w-full min-w-64 rounded-md border bg-popover text-popover-foreground shadow-md',
                        upward ? 'bottom-full mb-1' : 'mt-1',
                    )}
                >
                    <div className="flex items-center gap-2 border-b px-2.5">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            autoFocus
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    setOpen(false);
                                }

                                // Enter takes the first match and must not send the form around it.
                                if (event.key === 'Enter') {
                                    event.preventDefault();

                                    if (options[0]) {
                                        choose(options[0]);
                                    }
                                }
                            }}
                            placeholder="Search…"
                            aria-label="Search"
                            className="h-9 w-full bg-transparent text-sm outline-none"
                        />
                    </div>
                    <ul
                        ref={list}
                        role="listbox"
                        className="max-h-72 overflow-y-auto p-1"
                    >
                        {!required && search === '' && (
                            <li
                                role="option"
                                aria-selected={current === ''}
                                onClick={() => choose(null)}
                                className="cursor-pointer rounded px-2 py-1.5 text-sm text-muted-foreground hover:bg-accent"
                            >
                                {placeholder}
                            </li>
                        )}
                        {options.map((option) => (
                            <li
                                key={option.id}
                                role="option"
                                aria-selected={option.id === current}
                                onClick={() => choose(option)}
                                className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent"
                            >
                                {pictured && <Picture option={option} />}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate">
                                        {option.name}
                                    </span>
                                    {option.hint && (
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {option.hint}
                                        </span>
                                    )}
                                </span>
                                {option.id === current && (
                                    <Check className="size-4 shrink-0" />
                                )}
                            </li>
                        ))}
                        {!loading && options.length === 0 && (
                            <li className="px-2 py-1.5 text-sm text-muted-foreground">
                                Nothing found
                            </li>
                        )}
                        <li ref={end} className="h-px" />
                        {loading && (
                            <li className="px-2 py-1.5 text-sm text-muted-foreground">
                                Loading…
                            </li>
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
}
