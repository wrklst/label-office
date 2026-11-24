import { usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    AudioLines,
    Banknote,
    BookMarked,
    BookOpenText,
    CalendarRange,
    Coins,
    Disc3,
    FileSignature,
    FileText,
    PenLine,
    LayoutGrid,
    LibraryBig,
    ListMusic,
    Mic2,
    Package,
    PiggyBank,
    Radio,
    Receipt,
    Tags,
    TrendingUp,
    Upload,
    UserRound,
    Users,
} from 'lucide-react';
import { LabelSwitcher } from '@/components/label-switcher';
import { NavMain } from '@/components/nav-main';
import type { NavGroup } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, handbook } from '@/routes';
import { index as airplays } from '@/routes/airplays';
import { index as artists } from '@/routes/artists';
import { index as catalog } from '@/routes/catalog';
import { index as agreementTemplates } from '@/routes/agreement-templates';
import { index as agreements } from '@/routes/agreements';
import { index as myAgreements } from '@/routes/my-agreements';
import { index as contracts } from '@/routes/contracts';
import { index as dataImports } from '@/routes/data-imports';
import { index as items } from '@/routes/items';
import { index as labelCosts } from '@/routes/label-costs';
import { index as ledgers } from '@/routes/ledgers';
import { index as payees } from '@/routes/payees';
import { index as payouts } from '@/routes/payouts';
import { index as periods } from '@/routes/periods';
import { index as playCounts } from '@/routes/play-counts';
import { index as portal } from '@/routes/portal';
import { index as profitability } from '@/routes/profitability';
import { index as releases } from '@/routes/releases';
import { index as mechanicals } from '@/routes/royalty-tools/mechanicals';
import { index as ppd } from '@/routes/royalty-tools/ppd';
import { index as reserves } from '@/routes/royalty-tools/reserves';
import { index as statements } from '@/routes/statements';
import { index as tracks } from '@/routes/tracks';
import { index as transactions } from '@/routes/transactions';

/**
 * The groups are the chapters of the handbook of the same name, in its
 * order. Dashboard and Handbook stand above them.
 */
const adminGroups: NavGroup[] = [
    {
        items: [
            { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
            { title: 'Handbook', href: handbook(), icon: BookMarked },
        ],
    },
    {
        title: 'Importing',
        items: [
            { title: 'Data imports', href: dataImports(), icon: Upload },
            { title: 'Play counts', href: playCounts(), icon: AudioLines },
        ],
    },
    {
        title: 'Accounting',
        items: [
            {
                title: 'Transactions',
                href: transactions(),
                icon: ArrowLeftRight,
            },
            { title: 'Periods', href: periods(), icon: CalendarRange },
            { title: 'Statements', href: statements(), icon: FileText },
            { title: 'Payouts', href: payouts(), icon: Banknote },
        ],
    },
    {
        title: 'Deals',
        items: [
            { title: 'Contracts', href: contracts(), icon: FileSignature },
            { title: 'Agreements', href: agreements(), icon: PenLine },
            { title: 'Payees', href: payees(), icon: Users },
            { title: 'Ledgers', href: ledgers(), icon: BookOpenText },
            {
                title: 'Agreement templates',
                href: agreementTemplates(),
                icon: FileSignature,
            },
        ],
    },
    {
        title: 'Catalog',
        items: [
            { title: 'Catalog manager', href: catalog(), icon: LibraryBig },
            { title: 'Releases', href: releases(), icon: Disc3 },
            { title: 'Tracks', href: tracks(), icon: ListMusic },
            { title: 'Artists', href: artists(), icon: Mic2 },
            { title: 'Items', href: items(), icon: Package },
            { title: 'Airplay', href: airplays(), icon: Radio },
        ],
    },
    {
        title: 'Label',
        items: [
            {
                title: 'Profitability',
                href: profitability(),
                icon: TrendingUp,
            },
            { title: 'Overheads', href: labelCosts(), icon: Receipt },
        ],
    },
];

const payeeGroups: NavGroup[] = [
    {
        items: [
            { title: 'My statements', href: portal(), icon: FileText },
            { title: 'My agreements', href: myAgreements(), icon: PenLine },
        ],
    },
];

/**
 * The mechanicals, PPD and reserves tools appear under Accounting only for
 * a label that switched them on.
 */
function withTools(
    groups: NavGroup[],
    tools: { mechanicals: boolean; ppd: boolean; reserves: boolean },
): NavGroup[] {
    const extra = [
        tools.mechanicals && {
            title: 'Mechanicals',
            href: mechanicals(),
            icon: Coins,
        },
        tools.ppd && { title: 'PPD', href: ppd(), icon: Tags },
        tools.reserves && {
            title: 'Reserves',
            href: reserves(),
            icon: PiggyBank,
        },
    ].filter((item) => item !== false);

    return groups.map((group) =>
        group.title === 'Accounting'
            ? { ...group, items: [...group.items, ...extra] }
            : group,
    );
}

/**
 * Someone who only is a payee sees their own name at the top: they work in
 * no label, so there is nothing to switch.
 */
function PayeeHeader() {
    const { auth } = usePage().props;

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    <div data-payee-header>
                        <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                            <UserRound className="size-5" />
                        </div>
                        <div className="grid flex-1 text-left text-sm leading-tight">
                            <span className="truncate font-semibold">
                                {auth.user?.name}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                Payee
                            </span>
                        </div>
                    </div>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

export function AppSidebar() {
    const { label, labels, isPayee } = usePage().props;
    const runsLabel = labels.length > 0;
    // Only someone who is a payee is headed with their own name; a new operator without a label sees "No label yet".
    const payeeOnly = !runsLabel && isPayee;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                {payeeOnly ? <PayeeHeader /> : <LabelSwitcher />}
            </SidebarHeader>

            <SidebarContent>
                {label && (
                    <NavMain
                        groups={
                            runsLabel
                                ? [
                                      ...withTools(adminGroups, label.tools),
                                      ...(isPayee
                                          ? [
                                                {
                                                    ...payeeGroups[0],
                                                    title: 'As a payee',
                                                },
                                            ]
                                          : []),
                                  ]
                                : payeeGroups
                        }
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
