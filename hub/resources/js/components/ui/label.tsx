import * as LabelPrimitive from '@radix-ui/react-label';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/cn';

export function Label({ className, ...props }: ComponentProps<typeof LabelPrimitive.Root>) {
    return <LabelPrimitive.Root className={cn('igr:text-[13px] igr:font-medium igr:leading-none', className)} {...props} />;
}
