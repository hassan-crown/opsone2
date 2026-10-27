import * as SwitchPrimitive from "@radix-ui/react-switch"
import * as React from "react"

import { cn } from "@/lib/utils"

function Switch({
  className,
  ...props
}: React.ComponentProps<typeof SwitchPrimitive.Root>) {
  return (
    <SwitchPrimitive.Root
      data-slot="switch"
      className={cn(
        "relative h-6 w-11 shrink-0 cursor-pointer rounded-full border border-transparent bg-stroke-control transition-colors before:absolute before:-inset-x-0.5 before:-inset-y-2.5 before:content-[''] hover:bg-weak data-[state=checked]:bg-brand data-[state=checked]:hover:bg-brand-hover disabled:cursor-not-allowed disabled:bg-disabled-bg",
        className
      )}
      {...props}
    >
      <SwitchPrimitive.Thumb
        data-slot="switch-thumb"
        className="pointer-events-none absolute start-0.5 top-0.5 size-4.5 rounded-full bg-switch-thumb transition-[inset-inline-start] data-[state=checked]:start-5.5 data-[state=checked]:bg-on-brand"
      />
    </SwitchPrimitive.Root>
  )
}

export { Switch }
