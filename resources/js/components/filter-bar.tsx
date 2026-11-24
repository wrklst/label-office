import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { CheckField, SelectInput } from '@/components/field';
import { DateRange } from '@/components/date-range';
import { RecordSelect } from '@/components/record-select';
import type { RecordKind } from '@/components/record-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';

type Choices = ComponentProps<typeof SelectInput>['options'];

type FilterValue = string | number | boolean | null | undefined;

/**
 * The filter row of every list page: a search box, selects, checkboxes
 * and, when any filter is set, "Clear filters". Filters live in the query
 * string; changing one returns the list to its first page, and the order
 * of the list is kept.
 *
 * `children` is for what only one page has; `keys` names the query
 * parameters those controls set, so that they count as filters too.
 */
export function FilterBar({
    url,
    filters,
    search,
    selects = [],
    checks = [],
    dates = false,
    keys = [],
    children,
}: {
    url: string;
    filters: object;
    /** The placeholder of the search box; no box without it. */
    search?: string;
    selects?: {
        name: string;
        label: string;
        placeholder: string;
        options?: Choices;
        /** Records of the label instead of fixed choices: a dropdown searched on the server. */
        records?: RecordKind;
        className?: string;
        /** Fixed choices written as <option>s, for values that are not records. */
        children?: ReactNode;
    }[];
    /** Checkboxes, worded as the bare state: "Unassigned", "Without contract". */
    checks?: { name: string; label: string }[];
    /** Add the from, to and sale or paid date controls. */
    dates?: boolean;
    keys?: string[];
    children?: ReactNode;
}) {
    const current = filters as Record<string, FilterValue>;
    const { apply } = useFilters(url, current);
    // The search box and the dates keep what was typed; clearing remounts them empty.
    const [cleared, setCleared] = useState(0);

    const names = [
        ...(search !== undefined ? ['search'] : []),
        ...selects.map((select) => select.name),
        ...checks.map((check) => check.name),
        ...(dates ? ['from', 'to', 'date'] : []),
        ...keys,
    ];
    const isSet = (name: string) => {
        const value = current[name];

        return (
            value !== undefined &&
            value !== null &&
            value !== '' &&
            value !== false
        );
    };
    const filtered = names.some(isSet);

    const clear = () => {
        setCleared((value) => value + 1);
        apply(Object.fromEntries(names.map((name) => [name, null])) as never);
    };

    return (
        <div key={cleared} className="flex flex-wrap items-center gap-3">
            {search !== undefined && (
                <Input
                    type="search"
                    aria-label="Search"
                    placeholder={search}
                    defaultValue={String(current.search ?? '')}
                    onChange={(event) =>
                        apply({ search: event.target.value } as never, true)
                    }
                    className="w-full sm:max-w-sm"
                />
            )}
            {selects.map((select) =>
                select.records ? (
                    <RecordSelect
                        key={select.name}
                        id={`filter-${select.name}`}
                        aria-label={select.label}
                        records={select.records}
                        value={String(current[select.name] ?? '')}
                        onChange={(id) =>
                            apply({ [select.name]: String(id) } as never)
                        }
                        placeholder={select.placeholder}
                        className={select.className ?? 'w-full sm:w-52'}
                    />
                ) : (
                    <SelectInput
                        key={select.name}
                        aria-label={select.label}
                        value={String(current[select.name] ?? '')}
                        onChange={(event) =>
                            apply({
                                [select.name]: event.target.value,
                            } as never)
                        }
                        placeholder={select.placeholder}
                        options={select.options}
                        className={select.className ?? 'w-full sm:w-52'}
                    >
                        {select.children}
                    </SelectInput>
                ),
            )}
            {dates && <DateRange url={url} filters={filters} />}
            {checks.map((check) => (
                <CheckField
                    key={check.name}
                    label={check.label}
                    checked={
                        current[check.name] === true ||
                        current[check.name] === '1' ||
                        current[check.name] === 'true' ||
                        current[check.name] === 1
                    }
                    onChange={(event) =>
                        apply({ [check.name]: event.target.checked } as never)
                    }
                />
            ))}
            {children}
            {filtered && (
                <Button variant="ghost" size="sm" onClick={clear}>
                    Clear filters
                </Button>
            )}
        </div>
    );
}
