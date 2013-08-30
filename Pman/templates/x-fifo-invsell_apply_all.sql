CREATE OR REPLACE FUNCTION invsell_apply_all() RETURNS integer
AS $BODY$
DECLARE
     v_cnt integer;
     
BEGIN

-- find all the matches that we can update, and make the changes.
    SELECT COUNT(invsell_invhist_id) into v_cnt FROM 
        invsell
         
        WHERE
            ROUND(invsell_current_totalcost,2)  !=  ROUND(invsell_calc_totalcost,2)
            AND
            invsell_is_estimate = false   ;
            
            
    
    IF (v_cnt < 1) THEN
        RETURN 0;
    END IF;
    
    SELECT invsell_invhist_id INTO v_cnt
         FROM
            invsell
         
        WHERE
            ROUND(invsell_current_totalcost,2)  !=  ROUND(invsell_calc_totalcost,2)
            AND
            invsell_is_estimate = false
        ORDER BY
            invsell_invhist_id ASC 
        LIMIT 1    ;
    
    RAISE NOTICE 'APPLY INVSELL TO %', v_cnt;
-- updates invhist and gltrans
    PERFORM  
            invsell_apply_invsell(
                v_cnt
                
            ) as result;
        
     
    RETURN v_cnt;
            
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100;
  
ALTER FUNCTION  invsell_apply_all()
  OWNER TO admin;
  
  
  
  
  
  
  
  
  
  
  
  
  
CREATE OR REPLACE FUNCTION invsell_apply_invsell(integer) RETURNS integer
AS $BODY$
DECLARE
    i_invsell_invhist_id ALIAS FOR $1;
    v_mininvsell_transdate timestamp with time zone;
    
     
BEGIN
    --RAISE NOTICE 'invsell_apply_invsell:i_invsell_invhist_id =%', i_invsell_invhist_id;
-- find all the matches that we can update, and make the changes.

    --RAISE NOTICE 'invsell_apply';
-- updates invhist and gltrans
    PERFORM  
            invsell_apply(
                invsell_invhist_id,
                invhist_id
            ) as result
        FROM
            invhist
        INNER JOIN
            invdepend ON invhist_id = invdepend_invhist_id
        INNER JOIN
            invsell ON invdepend_parent_id = invsell_invhist_id
        
        WHERE
            invsell_invhist_id = i_invsell_invhist_id;
    
    
-- updates invhist and itemsite.
    --RAISE NOTICE 'invsell_apply_order';

    PERFORM
            invsell_apply_order (
                invsell_invhist_id
            ) as result
        FROM
            invsell
        WHERE
            invsell_invhist_id = i_invsell_invhist_id;
    
    --RAISE NOTICE 'calc min';
    SELECT min(invsell_transdate)
            INTO v_mininvsell_transdate
            FROM invsell
            WHERE
                invsell_itemsite_id = (
                    SELECT invsell_itemsite_id FROM invsell WHERE  invsell_invhist_id = i_invsell_invhist_id LIMIT 1
                );
                
    -- on invsell
    
    --RAISE NOTICE 'asset trialbal';
    PERFORM
            invsell_apply_trialbal (
                costcat_asset_accnt_id,
                 v_mininvsell_transdate
            ) as result
        FROM
            invsell
        LEFT JOIN itemsite ON itemsite_id = invsell_itemsite_id
        LEFT JOIN costcat ON costcat_id = itemsite_costcat_id
        WHERE
            invsell_invhist_id = i_invsell_invhist_id;
    
    -- on invsell
    --RAISE NOTICE 'shipasset trialbal';
    PERFORM
            invsell_apply_trialbal (
                costcat_shipasset_accnt_id,
                 v_mininvsell_transdate
            ) as result
        FROM
            invsell
        LEFT JOIN itemsite ON itemsite_id = invsell_itemsite_id
        LEFT JOIN costcat ON costcat_id = itemsite_costcat_id
        WHERE
            invsell_invhist_id = i_invsell_invhist_id;
    
    -- on invsell
    --RAISE NOTICE 'Cogs trialbal';
    PERFORM
            invsell_apply_trialbal (
                resolvecosaccount(invsell_itemsite_id, cohead_cust_id),
                 v_mininvsell_transdate
            ) as result
             
        FROM
            invsell
        LEFT JOIN cohead ON 
            cohead_number = CASE strpos(invsell_ordnumber, '-')
                        WHEN 0 THEN invsell_ordnumber 
                        ELSE substr(invsell_ordnumber, 1, strpos(invsell_ordnumber, '-') - 1)
                    END
          WHERE
            invsell_invhist_id = i_invsell_invhist_id;
    RETURN 1;
            
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100;
  
ALTER FUNCTION  invsell_apply_invsell(integer)
  OWNER TO admin;
  
  
