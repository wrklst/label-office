import ReleaseController from '@/actions/App/Http/Controllers/ReleaseController';
import { CheckField, Field } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import { RecordLink } from '@/components/record-link';
import { RecordSelect } from '@/components/record-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Option } from '@/types';

/**
 * What the contracts of a release's tracks add up to: the one contract, or
 * a note that the tracks have several or some have none. A release has no
 * contract of its own.
 */
export function ReleaseContract({
    contract,
    mixed,
    className,
}: {
    contract: Option | null;
    mixed: boolean;
    className?: string;
}) {
    if (contract) {
        return (
            <RecordLink to="contract" id={contract.id} className={className}>
                {contract.name}
            </RecordLink>
        );
    }

    return (
        <Badge variant="warning">
            {mixed ? 'Several or some missing' : 'No contract'}
        </Badge>
    );
}

/**
 * Writes one contract on the tracks of a release: on those without one, or
 * on all of them.
 */
export function SetContractDialog({ releaseId }: { releaseId: number }) {
    return (
        <FormDialog
            title="Set contract on all tracks"
            description="A release has no contract of its own. This writes the contract on its tracks."
            form={ReleaseController.contract.form(releaseId)}
            submitLabel="Set contract"
            trigger={
                <Button type="button" variant="outline" size="sm">
                    Set contract on all tracks
                </Button>
            }
        >
            {(errors) => (
                <>
                    <Field
                        label="Contract"
                        htmlFor="contract_id"
                        error={errors.contract_id}
                        hint="Leave empty to remove the contract."
                    >
                        <RecordSelect
                            id="contract_id"
                            name="contract_id"
                            records="contracts"
                            placeholder="No contract"
                        />
                    </Field>
                    <CheckField
                        name="replace"
                        value="1"
                        label="Also replace the contracts tracks already have"
                        hint="Without it, tracks that have a contract keep it."
                    />
                </>
            )}
        </FormDialog>
    );
}
