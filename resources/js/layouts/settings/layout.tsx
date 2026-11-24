import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editBackups } from '@/routes/backups';
import { edit as editBandcamp } from '@/routes/bandcamp';
import { edit as editConnections } from '@/routes/connections';
import { edit as editLabel } from '@/routes/label-settings';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as team } from '@/routes/team';
import { edit as editUpdates } from '@/routes/updates';
import type { NavItem } from '@/types';

const accountNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

const labelNavItems: NavItem[] = [
    {
        title: 'Label',
        href: editLabel(),
        icon: null,
    },
    {
        title: 'Team',
        href: team(),
        icon: null,
    },
    {
        title: 'Connections',
        href: editConnections(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { label, auth } = usePage().props;
    const sidebarNavItems =
        label?.role === 'admin'
            ? [
                  ...accountNavItems,
                  ...labelNavItems,
                  // The installation's own admins look after its backups and its version.
                  ...(auth.user.installation_admin
                      ? [
                            {
                                title: 'Backups',
                                href: editBackups(),
                                icon: null,
                            },
                            {
                                title: 'Updates',
                                href: editUpdates(),
                                icon: null,
                            },
                        ]
                      : []),
              ]
            : accountNavItems;

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Manage your account, your label and who has access."
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Settings"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    // The Bandcamp page is reached from its card on Connections.
                                    'bg-muted':
                                        isCurrentOrParentUrl(item.href) ||
                                        (item.title === 'Connections' &&
                                            isCurrentOrParentUrl(
                                                editBandcamp(),
                                            )),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
