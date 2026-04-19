Mark the specified task (e.g. BACK-5) as done — but only after verifying all of the following:

- [ ] All acceptance criteria from the task are met
- [ ] Existing tests still pass
- [ ] New logic is covered by tests
- [ ] No obvious linting issues

If any check fails, report which one and do not mark the task as done. Once all checks pass, run:
`backlog task edit <ID> -s "Done"`
