# License contract vectors (copy)

`v1/` is a byte-for-byte copy of `src/contract/license/v1/` from `hassan-crown/opsone-central`,
`main` at commit `d4288091bae0383075ec275c251dbdcb5f022407`
(last commit touching the directory: `027a302f47c2f49d14da9a7b8e6209e6ac91a6bf`).

Do not edit the copy. To update it, re-copy from the central app and change the commit above.
It is consumed only by `tests/Feature/Licensing/LicenseContractTest.php`, which injects the
public test key `opsone-contract-test` into the verifier; that key is never added to configuration.
