import { cn } from "@/lib/utils"

function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="skeleton"
      aria-hidden="true"
      className={cn("block animate-o1-pulse rounded-sm bg-skeleton", className)}
      {...props}
    />
  )
}

export { Skeleton }
