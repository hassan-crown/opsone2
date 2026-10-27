import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const alertVariants = cva(
  "relative flex w-full items-start gap-3 rounded-md border border-transparent px-4 py-3 text-body text-strong contrast:border-stroke-control [&>svg]:mt-0.5 [&>svg]:size-5 [&>svg]:shrink-0",
  {
    variants: {
      variant: {
        info: "bg-info-bg [&>svg]:text-info-text",
        success: "bg-success-bg [&>svg]:text-success-text",
        warning: "bg-warning-bg [&>svg]:text-warning-text",
        danger: "bg-danger-bg [&>svg]:text-danger-text",
        default: "bg-info-bg [&>svg]:text-info-text",
        destructive: "bg-danger-bg [&>svg]:text-danger-text",
      },
    },
    defaultVariants: {
      variant: "info",
    },
  }
)

function Alert({
  className,
  variant,
  role,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof alertVariants>) {
  const urgent = variant === "danger" || variant === "destructive"

  return (
    <div
      data-slot="alert"
      role={role ?? (urgent ? "alert" : "status")}
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  )
}

function AlertBody({ className, ...props }: React.ComponentProps<"div">) {
  return <div className={cn("min-w-0 flex-1", className)} {...props} />
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn("font-semibold", className)}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn("text-body [&_p]:leading-relaxed", className)}
      {...props}
    />
  )
}

export { Alert, AlertBody, AlertTitle, AlertDescription }
