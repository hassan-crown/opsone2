import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex min-h-7 items-center gap-1 whitespace-nowrap rounded-sm border border-transparent px-2.5 py-1 text-label contrast:border-current [&>svg]:size-4 [&>svg]:shrink-0",
  {
    variants: {
      variant: {
        brand: "bg-brand-subtle text-brand-subtle-text",
        success: "bg-success-bg text-success-text",
        warning: "bg-warning-bg text-warning-text",
        danger: "bg-danger-bg text-danger-text",
        info: "bg-info-bg text-info-text",
        neutral: "bg-neutral-bg text-neutral-text",
        // shadcn names kept for starter code
        default: "bg-brand-subtle text-brand-subtle-text",
        secondary: "bg-neutral-bg text-neutral-text",
        destructive: "bg-danger-bg text-danger-text",
        outline: "border-stroke-control text-strong",
      },
    },
    defaultVariants: {
      variant: "neutral",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
