import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md border border-transparent text-control cursor-pointer select-none transition-colors aria-disabled:cursor-not-allowed disabled:cursor-not-allowed aria-busy:cursor-progress [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-5",
  {
    variants: {
      variant: {
        default:
          "bg-brand text-on-brand hover:bg-brand-hover active:bg-brand-active",
        destructive:
          "bg-danger text-on-danger hover:bg-danger-hover active:bg-danger-hover",
        outline:
          "bg-surface text-strong border-stroke-control hover:bg-hover active:bg-stroke-weak",
        secondary:
          "bg-surface text-strong border-stroke-control hover:bg-hover active:bg-stroke-weak",
        ghost:
          "bg-transparent text-link hover:bg-hover active:bg-brand-subtle active:text-brand-subtle-text",
        link: "text-link underline-offset-4 hover:underline",
      },
      size: {
        default: "min-h-11 px-5",
        sm: "min-h-9 px-4",
        lg: "min-h-11 px-6",
        icon: "size-11 p-0",
        "icon-sm": "size-9 p-0",
      },
      block: {
        true: "flex w-full",
        false: "",
      },
    },
    compoundVariants: [
      { variant: "ghost", size: ["default", "lg"], className: "px-3" },
    ],
    defaultVariants: {
      variant: "default",
      size: "default",
      block: false,
    },
  }
)

const disabledClasses =
  "disabled:bg-disabled-bg disabled:text-disabled-text disabled:border-transparent aria-disabled:bg-disabled-bg aria-disabled:text-disabled-text aria-disabled:border-transparent"

function Button({
  className,
  variant,
  size,
  block,
  asChild = false,
  loading = false,
  disabled,
  children,
  onClick,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    /** Keeps the button focusable and announces busy state; clicks are ignored. */
    loading?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      aria-busy={loading || undefined}
      className={cn(
        buttonVariants({ variant, size, block }),
        !loading && variant !== "link" && disabledClasses,
        variant === "ghost" && "disabled:bg-transparent aria-disabled:bg-transparent",
        className
      )}
      disabled={disabled}
      onClick={loading ? (event: React.MouseEvent<HTMLButtonElement>) => event.preventDefault() : onClick}
      {...props}
    >
      {children}
    </Comp>
  )
}

export { Button, buttonVariants }
