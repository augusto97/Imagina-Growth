import type { ReactNode } from 'react';
import { Logo } from '@/components/layout/Logo';

export function AuthLayout({ title, description, children, footer }: { title: string; description?: ReactNode; children: ReactNode; footer?: ReactNode }) {
    return (
        <div className="igr:flex igr:min-h-full igr:flex-col igr:items-center igr:justify-center igr:bg-muted/40 igr:px-4 igr:py-12">
            <div className="igr:mb-6">
                <Logo />
            </div>
            <div className="igr:w-full igr:max-w-sm igr:rounded-xl igr:border igr:bg-card igr:p-6 igr:shadow-sm">
                <h1 className="igr:text-base igr:font-semibold">{title}</h1>
                {description ? <p className="igr:mt-1 igr:text-[13px] igr:text-muted-foreground">{description}</p> : null}
                <div className="igr:mt-5">{children}</div>
            </div>
            {footer ? <div className="igr:mt-4 igr:text-[13px] igr:text-muted-foreground">{footer}</div> : null}
        </div>
    );
}
