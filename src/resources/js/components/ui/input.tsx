import * as React from "react"

import { cn } from "@/lib/utils"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        "field-control w-full min-w-0 text-control placeholder:font-normal file:me-3 file:border-0 file:bg-transparent file:text-label",
        className
      )}
      {...props}
    />
  )
}

export { Input }
